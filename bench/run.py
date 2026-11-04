#!/usr/bin/env python3
"""
Reproducible benchmark: Soul vs Go / Python / find / rsync / cp.

  python3 bench/run.py [--files 20000] [--runs 7] [--scenario scan|backup-full|backup-incr|all]
                       [--profile small-files|big-files] [--workers 4] [--keep] [--cold] [--workdir DIR]

For every scenario each tool is (1) run once and its result CHECKED against a
reference, (2) run --runs times, timed, and summarised as median/min/max/stdev.
A tool whose result is wrong is reported as INVALID and its time is not shown:
a fast wrong answer is not a result.

Tools that are not installed (rsync, ...) are skipped, and the report says so.
Soul is built with `php soulc.php build` from this checkout.
"""
import argparse
import hashlib
import json
import os
import platform
import re
import resource
import shutil
import statistics
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
BASE = os.path.join(HERE, "baselines")
BIN = os.path.join(BASE, "bin")


# ----------------------------------------------------------------- utilities
def which(x):
    return shutil.which(x)


def sh(cmd, **kw):
    return subprocess.run(cmd, capture_output=True, text=True, **kw)


def drop_caches():
    try:
        os.sync()
        with open("/proc/sys/vm/drop_caches", "w") as f:
            f.write("3\n")
        return True
    except OSError:
        return False


def manifest(root):
    """{relative path: sha256} for every regular file under root."""
    out = {}
    for d, _, names in os.walk(root):
        for n in names:
            p = os.path.join(d, n)
            h = hashlib.sha256()
            with open(p, "rb") as f:
                for chunk in iter(lambda: f.read(1 << 20), b""):
                    h.update(chunk)
            out[os.path.relpath(p, root)] = h.hexdigest()
    return out


TIMEOUT = 900  # seconds per process: a hung tool must fail loudly, not stall the suite


def run_timed(argv, env=None, procs=1, extra_envs=None):
    """Run a command (or `procs` copies, each with its own env) and time it.
    Returns (wall_seconds, cpu_seconds, stdout_of_first, ok)."""
    before = resource.getrusage(resource.RUSAGE_CHILDREN)
    t0 = time.perf_counter()
    if procs == 1:
        try:
            r = subprocess.run(argv, capture_output=True, text=True, env=env, timeout=TIMEOUT)
            out, ok = r.stdout, r.returncode == 0
        except subprocess.TimeoutExpired:
            out, ok = "", False
    else:
        ps = []
        for i in range(procs):
            e = dict(env or os.environ)
            e.update(extra_envs(i))
            ps.append(subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=e))
        outs = []
        for p in ps:
            try:
                outs.append(p.communicate(timeout=TIMEOUT))
            except subprocess.TimeoutExpired:
                p.kill()
                outs.append(("", ""))
                p.returncode = -9
        ok = all(p.returncode == 0 for p in ps)
        out = outs[0][0]
    wall = time.perf_counter() - t0
    after = resource.getrusage(resource.RUSAGE_CHILDREN)
    cpu = (after.ru_utime - before.ru_utime) + (after.ru_stime - before.ru_stime)
    return wall, cpu, out, ok


# ------------------------------------------------------------- soul building
def build_soul(name):
    """Compile library/tools/<name>.soul; returns binary path or None."""
    r = sh(["php", "soulc.php", "build", "library/tools/%s.soul" % name], cwd=ROOT)
    p = os.path.join(ROOT, "out", "tools", name)
    if r.returncode != 0 or not os.path.isfile(p):
        print("  could not build soul %s:\n%s" % (name, (r.stdout + r.stderr)[-400:]), file=sys.stderr)
        return None
    return p


def build_go():
    if not which("go"):
        return False
    ok = True
    for pkg, out in (("./scan", "scan-go"), ("./backup", "backup-go")):
        os.makedirs(BIN, exist_ok=True)
        r = sh(["go", "build", "-o", os.path.join(BIN, out), pkg], cwd=BASE)
        if r.returncode != 0:
            print("  go build %s failed:\n%s" % (pkg, r.stderr[-400:]), file=sys.stderr)
            ok = False
    return ok


# ------------------------------------------------------------------ scenarios
class Tool:
    def __init__(self, name, argv, parse=None, procs=1, extra_envs=None, note="", env=None):
        self.name, self.argv, self.parse = name, argv, parse
        self.procs, self.extra_envs, self.note, self.env = procs, extra_envs, note, env


def parse_scan_line(out):
    m = re.search(r"files=(\d+) dirs=(\d+) bytes=(\d+) exts=(\d+) top1=(\d+)", out)
    return tuple(int(x) for x in m.groups()) if m else None


def parse_soul_analyzer(out):
    try:
        files = int(re.search(r"^Files:\s*\n(\d+)", out, re.M).group(1))
        dirs = int(re.search(r"^Dirs:\s*\n(\d+)", out, re.M).group(1))
        size = int(re.search(r"^Size:\s*\n(\d+)", out, re.M).group(1))
        exts = len(re.findall(r"^\S+ -> \d+ files, \d+ bytes$", out, re.M))
        top = re.search(r"Top biggest files:\n(.+?) -> (\d+)", out)
        return (files, dirs, size, exts, int(top.group(2)))
    except (AttributeError, ValueError):
        return None


def scan_tools(src, workers, soul_analyzer):
    t = []
    if soul_analyzer:
        t.append(Tool("soul analyzer", [soul_analyzer, "--folder", src, "--top", "10"], parse_soul_analyzer))
    if os.path.isfile(os.path.join(BIN, "scan-go")):
        t.append(Tool("go WalkDir (sequential)", [os.path.join(BIN, "scan-go"), src], parse_scan_line))
        t.append(Tool("go parallel x%d" % workers, [os.path.join(BIN, "scan-go"), "-workers", str(workers), src], parse_scan_line))
    t.append(Tool("python scandir", [sys.executable, os.path.join(BASE, "scan.py"), src], parse_scan_line))
    t.append(Tool("find + awk", [os.path.join(BASE, "scan_find.sh"), src], parse_scan_line))
    return t


def backup_tools(src, dst, workers, soul_backup):
    t = []
    if soul_backup:
        # `soul backup` forks 4 worker processes ITSELF when run directly
        # (soul_parallel_run(4) in the runtime), whatever the core count.
        t.append(Tool("soul backup (default: forks 4 procs)", [soul_backup, "--folderSrc", src, "--folderDst", dst],
                      note="hash-verifies copies; forks 4 processes by itself"))
        t.append(Tool("soul backup (default, --no-verify)", [soul_backup, "--folderSrc", src, "--folderDst", dst, "--no-verify"],
                      note="forks 4 processes by itself"))
        env1 = dict(os.environ, SOUL_WORKER_ID="0", SOUL_WORKER_COUNT="1")
        t.append(Tool("soul backup, 1 process (SOUL_WORKER_COUNT=1)",
                      [soul_backup, "--folderSrc", src, "--folderDst", dst, "--no-verify"], env=env1,
                      note="the like-for-like comparison with the sequential baselines"))
        t.append(Tool("soul backup, %d procs launched externally" % workers,
                      [soul_backup, "--folderSrc", src, "--folderDst", dst, "--no-verify"], procs=workers,
                      extra_envs=lambda i: {"SOUL_WORKER_ID": str(i), "SOUL_WORKER_COUNT": str(workers)},
                      note="as `soul run --parallel=N` launches it"))
    if os.path.isfile(os.path.join(BIN, "backup-go")):
        t.append(Tool("go sequential", [os.path.join(BIN, "backup-go"), src, dst]))
        t.append(Tool("go x%d workers" % workers, [os.path.join(BIN, "backup-go"), "-workers", str(workers), src, dst]))
        t.append(Tool("go sequential, read/write 64K", [os.path.join(BIN, "backup-go"), "-rw", src, dst],
                      note="same copy strategy as soul"))
        t.append(Tool("go x%d workers, read/write 64K" % workers, [os.path.join(BIN, "backup-go"), "-rw", "-workers", str(workers), src, dst],
                      note="same copy strategy as soul"))
    t.append(Tool("python sequential", [sys.executable, os.path.join(BASE, "backup.py"), src, dst]))
    t.append(Tool("python sequential, read/write 64K", [sys.executable, os.path.join(BASE, "backup.py"), "--rw", src, dst],
                  note="same copy strategy as soul"))
    if which("rsync"):
        t.append(Tool("rsync -a", ["rsync", "-a", src.rstrip("/") + "/", dst.rstrip("/") + "/"]))
    if which("cp"):
        t.append(Tool("cp -a (always full copy)", ["cp", "-a", src.rstrip("/") + "/.", dst]))
    return t


def summarise(times):
    return {
        "median": statistics.median(times),
        "min": min(times),
        "max": max(times),
        "stdev": statistics.stdev(times) if len(times) > 1 else 0.0,
        "n": len(times),
    }


def interleaved(tools, runs, prepare, cold):
    """Time every tool `runs` times, in ROUNDS: each round runs each tool once and
    the starting tool rotates. Running all repetitions of tool A and then all of
    tool B lets slow drift (disk write-back, throttled virtual disks, noisy
    neighbours) land on whoever happens to run last; in a first version of this
    harness that made later tools look 5-10x slower for no reason of their own."""
    times = {t.name: [] for t in tools}
    cpus = {t.name: [] for t in tools}
    n = len(tools)
    for r in range(runs):
        for t in tools[r % n:] + tools[:r % n]:
            prepare(t)
            if cold and not drop_caches():
                sys.exit("--cold needs permission to write /proc/sys/vm/drop_caches (run as root)")
            w, c, _, ok = run_timed(t.argv, env=t.env, procs=t.procs, extra_envs=t.extra_envs)
            if not ok:
                sys.exit("%s failed during timing" % t.name)
            times[t.name].append(w)
            cpus[t.name].append(c)
    out = {}
    for t in tools:
        s = summarise(times[t.name])
        s["cpu_median"] = statistics.median(cpus[t.name])
        out[t.name] = s
    return out


def bench_scan(a, src, res, skipped):
    soul = build_soul("analyzer")
    if not soul:
        skipped.append("soul analyzer (build failed)")
    tools = scan_tools(src, a.workers, soul)
    ref = parse_scan_line(sh([os.path.join(BASE, "scan_find.sh"), src]).stdout)
    print("\n== scan: files, dirs, bytes, extensions, biggest file  (reference: %s)" % (ref,))
    rows, valid = {}, []
    for t in tools:
        _, _, out, ok = run_timed(t.argv)
        got = t.parse(out) if ok else None
        if got != ref:
            rows[t.name] = {"tool": t.name, "invalid": "returned %s, expected %s" % (got, ref)}
            print("  %-34s INVALID: %s" % (t.name, rows[t.name]["invalid"]))
        else:
            valid.append(t)
    done = interleaved(valid, a.runs, lambda t: None, a.cold)
    for t in tools:
        if t.name in done:
            s = done[t.name]
            rows[t.name] = {"tool": t.name, **s}
            print("  %-34s median %7.3fs  min %7.3fs  max %7.3fs  sd %.3f" % (t.name, s["median"], s["min"], s["max"], s["stdev"]))
    res["scan"] = [rows[t.name] for t in tools]


def bench_backup(a, src, base_dst, incremental, res, skipped, label):
    soul = build_soul("backup")
    if not soul:
        skipped.append("soul backup (build failed)")
    work = os.path.join(a.workdir, "dst")
    want = manifest(src)
    print("\n== %s  (%d files; destination %s)" % (label, len(want),
          "pre-populated with yesterday's copy" if incremental else "empty"))

    def fresh(_t=None):
        shutil.rmtree(work, ignore_errors=True)
        if incremental:
            subprocess.run(["cp", "-a", base_dst, work], check=True)
        else:
            os.makedirs(work)
        os.sync()          # start every run with no dirty pages left over from the previous one
        time.sleep(0.3)

    rows, valid = {}, []
    for t in backup_tools(src, work, a.workers, soul):
        if t.name.startswith("cp -a") and incremental:
            rows[t.name] = {"tool": t.name, "skipped": "cp has no incremental mode (shown only in the full scenario)"}
            continue
        fresh()
        _, _, _, ok = run_timed(t.argv, env=t.env, procs=t.procs, extra_envs=t.extra_envs)
        got = manifest(work) if ok else None
        if got != want:
            missing = len(set(want) - set(got or {}))
            wrong = sum(1 for k in (got or {}) if k in want and got[k] != want[k])
            rows[t.name] = {"tool": t.name, "invalid": "destination differs from source (%s missing, %d wrong content, exit ok=%s)" % (missing, wrong, ok)}
            print("  %-36s INVALID: %s" % (t.name, rows[t.name]["invalid"]))
        else:
            valid.append(t)
    done = interleaved(valid, a.runs, fresh, a.cold)
    order = [t.name for t in backup_tools(src, work, a.workers, soul)]
    notes = {t.name: t.note for t in valid if t.note}
    for name in order:
        if name in done:
            s = done[name]
            rows[name] = {"tool": name, **s, **({"note": notes[name]} if name in notes else {})}
            print("  %-36s median %7.3fs  min %7.3fs  max %7.3fs  sd %.3f" % (name, s["median"], s["min"], s["max"], s["stdev"]))
    shutil.rmtree(work, ignore_errors=True)
    res[label] = [rows[n] for n in order if n in rows]


# --------------------------------------------------------------------- report
def environment(a):
    cpu = ""
    try:
        cpu = re.search(r"model name\s*:\s*(.+)", open("/proc/cpuinfo").read()).group(1)
    except Exception:
        pass
    def ver(cmd):
        try:
            return sh(cmd).stdout.strip().splitlines()[0]
        except Exception:
            return "n/a"
    fs = sh(["stat", "-f", "-c", "%T", a.workdir]).stdout.strip()
    return {
        "date": time.strftime("%Y-%m-%d %H:%M:%S"),
        "cpu": cpu, "cores": os.cpu_count(), "kernel": platform.release(), "filesystem": fs,
        "gcc": ver(["gcc", "--version"]), "go": ver(["go", "version"]) if which("go") else "not installed",
        "python": platform.python_version(), "rsync": ver(["rsync", "--version"]) if which("rsync") else "not installed",
        "soul build": "musl-gcc -O3 -static" if which("musl-gcc") else "gcc -O3 (dynamic glibc; musl-gcc not installed)",
        "cache": "cold (drop_caches before each run)" if a.cold else "warm (page cache populated)",
        "runs": a.runs, "files": a.files, "profile": a.profile, "workers": a.workers,
    }


def markdown(env, res, skipped):
    L = ["## Environment", ""]
    L += ["- %s: %s" % (k, v) for k, v in env.items()]
    for name, rows in res.items():
        valid = [r for r in rows if "median" in r]
        fastest = min((r["median"] for r in valid), default=None)
        L += ["", "## %s" % name, "", "| Tool | median | min | max | stdev | CPU (median) | vs fastest |", "|---|---|---|---|---|---|---|"]
        for r in rows:
            if "median" in r:
                L.append("| %s | %.3f s | %.3f s | %.3f s | %.3f | %.3f s | %.2fx |" % (
                    r["tool"], r["median"], r["min"], r["max"], r["stdev"], r["cpu_median"], r["median"] / fastest))
            elif "invalid" in r:
                L.append("| %s | INVALID: %s | | | | | |" % (r["tool"], r["invalid"]))
            else:
                L.append("| %s | skipped: %s | | | | | |" % (r["tool"], r["skipped"]))
    if skipped:
        L += ["", "Not run: " + ", ".join(skipped)]
    return "\n".join(L) + "\n"


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--files", type=int, default=20000)
    p.add_argument("--runs", type=int, default=7)
    p.add_argument("--workers", type=int, default=4)
    p.add_argument("--profile", choices=["small-files", "big-files"], default="small-files",
                   help="small-files: many ~4 KiB files (default). big-files: ~1,600 files / 1.4 GB, "
                        "the shape of the README's backup benchmark (use --files 1600)")
    p.add_argument("--scenario", choices=["scan", "backup-full", "backup-incr", "all"], default="all")
    p.add_argument("--workdir", default=os.path.join(HERE, "work"))
    p.add_argument("--keep", action="store_true", help="keep the generated dataset")
    p.add_argument("--cold", action="store_true", help="drop the page cache before every timed run (root only)")
    a = p.parse_args()

    a.workdir = os.path.abspath(a.workdir)
    if os.path.exists(a.workdir):
        sys.exit("workdir %s already exists; remove it or pass --workdir" % a.workdir)
    os.makedirs(a.workdir)
    skipped = []
    res = {}
    try:
        if a.cold and not drop_caches():
            sys.exit("--cold needs permission to write /proc/sys/vm/drop_caches (run as root)")
        print("building baselines...")
        if not build_go():
            skipped.append("go baselines (go missing or build failed)")
        if not which("rsync"):
            skipped.append("rsync (not installed)")
        if not which("fd"):
            skipped.append("fd (not installed; not part of the suite)")

        src = os.path.join(a.workdir, "src")
        gen = os.path.join(HERE, "gen_dataset.py")
        size_args = (["--median-size", "563000", "--sigma", "1.0", "--max-size", "33554432"]
                     if a.profile == "big-files" else [])
        print("generating dataset (%d files, profile %s)..." % (a.files, a.profile))
        sh([sys.executable, gen, "create", "--dest", src, "--files", str(a.files)] + size_args, check=True)

        if a.scenario in ("scan", "all"):
            bench_scan(a, src, res, skipped)
        if a.scenario in ("backup-full", "all"):
            bench_backup(a, src, None, False, res, skipped, "backup-full (first run, empty destination)")
        if a.scenario in ("backup-incr", "all"):
            base = os.path.join(a.workdir, "dst_base")
            subprocess.run(["cp", "-a", src, base], check=True)
            m = sh([sys.executable, gen, "mutate", "--dest", src] + size_args, check=True).stdout.strip()
            print("\nmutated source for the incremental scenario: %s" % m)
            bench_backup(a, src, base, True, res, skipped, "backup-incr (5%% changed, 1%% new)".replace("%%", "%"))

        env = environment(a)
        md = markdown(env, res, skipped)
        outdir = os.path.join(HERE, "results")
        os.makedirs(outdir, exist_ok=True)
        stamp = time.strftime("%Y%m%d-%H%M%S")
        with open(os.path.join(outdir, stamp + ".json"), "w") as f:
            json.dump({"environment": env, "results": res, "not_run": skipped}, f, indent=2)
        with open(os.path.join(outdir, stamp + ".md"), "w") as f:
            f.write(md)
        print("\n" + md)
        print("saved bench/results/%s.{json,md}" % stamp)
    finally:
        if not a.keep:
            shutil.rmtree(a.workdir, ignore_errors=True)


if __name__ == "__main__":
    main()
