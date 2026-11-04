#!/usr/bin/env python3
"""
Deterministic dataset generator for the Soul benchmarks.

  gen_dataset.py create --dest DIR [--files N] [--seed S] [SIZE OPTIONS]
  gen_dataset.py mutate --dest DIR [--seed S] [--changed 0.05] [--added 0.01] [SIZE OPTIONS]

SIZE OPTIONS (defaults give many small files):
  --median-size BYTES   median file size            (default 4096)
  --sigma X             log-normal spread           (default 1.5)
  --max-size BYTES      cap on a single file        (default 4194304)

"Few big files" profile, comparable to the README's ~1,600 files / 1.4 GB:
  --files 1600 --median-size 563000 --sigma 1.0 --max-size 33554432

`create` builds a directory tree with N regular files: log-normal sizes
(median ~4 KiB, capped at 4 MiB), a realistic mix of extensions, a tree about
4 levels deep, and fixed mtimes. The same (files, seed) always gives the same
bytes, so results can be compared across machines.

`mutate` turns the tree into the "second day" of an incremental backup:
  * half of the changed files get a different SIZE and a newer mtime,
  * the other half keep the SAME size but get new content and a newer mtime
    (a tool that only compares sizes would miss these),
  * a few brand-new files are added.
It prints a JSON summary with the exact counts.
"""
import argparse
import json
import math
import os
import random
import sys

BASE_MTIME = 1_700_000_000
EXTS = [("txt", 30), ("log", 20), ("json", 12), ("jpg", 8), ("py", 7), ("md", 6),
        ("csv", 5), ("bin", 4), ("html", 4), ("xml", 2), ("gz", 2)]
DEFAULT_MEDIAN, DEFAULT_SIGMA, DEFAULT_MAX = 4096, 1.5, 4 * 1024 * 1024


def pick_ext(rng):
    total = sum(w for _, w in EXTS)
    r = rng.uniform(0, total)
    for e, w in EXTS:
        r -= w
        if r <= 0:
            return e
    return EXTS[-1][0]


def pick_size(rng, a):
    s = int(rng.lognormvariate(math.log(a.median_size), a.sigma))
    return max(0, min(s, a.max_size))


def make_dirs(dest, n_files, rng):
    n_dirs = max(1, n_files // 40)
    dirs = [dest]
    os.makedirs(dest, exist_ok=True)
    for i in range(n_dirs):
        parent = rng.choice(dirs)
        depth = os.path.relpath(parent, dest).count(os.sep) + (0 if parent != dest else -1) + 1
        if depth >= 4:
            parent = dest
        d = os.path.join(parent, "d%04d" % i)
        os.makedirs(d, exist_ok=True)
        dirs.append(d)
    return dirs


def write_file(path, size, rng, mtime):
    with open(path, "wb") as f:
        f.write(rng.randbytes(size))
    os.utime(path, (mtime, mtime))


def create(a):
    rng = random.Random(a.seed)
    dest = os.path.abspath(a.dest)
    if os.path.exists(dest) and os.listdir(dest):
        sys.exit("refusing to write into non-empty directory: %s" % dest)
    dirs = make_dirs(dest, a.files, rng)
    total = 0
    for i in range(a.files):
        d = rng.choice(dirs)
        size = pick_size(rng, a)
        write_file(os.path.join(d, "f%07d.%s" % (i, pick_ext(rng))), size, rng, BASE_MTIME + i)
        total += size
    print(json.dumps({"files": a.files, "dirs": len(dirs), "bytes": total, "seed": a.seed}))


def mutate(a):
    rng = random.Random(a.seed + 1)
    dest = os.path.abspath(a.dest)
    files = []
    for root, _, names in os.walk(dest):
        for n in names:
            files.append(os.path.join(root, n))
    files.sort()
    n_changed = int(len(files) * a.changed)
    n_added = int(len(files) * a.added)
    chosen = rng.sample(files, n_changed)
    size_changed = same_size = 0
    for i, p in enumerate(chosen):
        old = os.stat(p)
        new_mtime = int(old.st_mtime) + 100_000
        if i % 2 == 0:
            new_size = old.st_size + 1 + rng.randrange(1, 2048)
            size_changed += 1
        else:
            new_size = old.st_size
            same_size += 1
        write_file(p, new_size, rng, new_mtime)
    dirs = [r for r, _, _ in os.walk(dest)]
    dirs.sort()
    for i in range(n_added):
        write_file(os.path.join(rng.choice(dirs), "new%06d.%s" % (i, pick_ext(rng))),
                   pick_size(rng, a), rng, BASE_MTIME + 200_000 + i)
    print(json.dumps({"changed_size": size_changed, "changed_same_size": same_size, "added": n_added}))


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="cmd", required=True)
    def size_opts(q):
        q.add_argument("--median-size", type=int, default=DEFAULT_MEDIAN)
        q.add_argument("--sigma", type=float, default=DEFAULT_SIGMA)
        q.add_argument("--max-size", type=int, default=DEFAULT_MAX)

    c = sub.add_parser("create")
    size_opts(c)
    c.add_argument("--dest", required=True)
    c.add_argument("--files", type=int, default=20000)
    c.add_argument("--seed", type=int, default=42)
    m = sub.add_parser("mutate")
    size_opts(m)
    m.add_argument("--dest", required=True)
    m.add_argument("--seed", type=int, default=42)
    m.add_argument("--changed", type=float, default=0.05)
    m.add_argument("--added", type=float, default=0.01)
    a = p.parse_args()
    create(a) if a.cmd == "create" else mutate(a)


if __name__ == "__main__":
    main()
