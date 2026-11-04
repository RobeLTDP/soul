# Soul — Compiled Language for Filesystem Automation

**Write filesystem tools in a few lines. Compile to a standalone binary. Run anywhere Linux runs.**

Soul is a compiled language designed specifically for filesystem operations — backup, cleanup, sync, analysis. It produces ultralight native binaries (16–30 KB) with no interpreter and no runtime to install. Built with musl (`soul build --docker`) they are fully static; a plain `soul build` uses `gcc -O3` and links dynamically against the system libc.

---

## Try it in 30 seconds

No installation required. Mount any local folder and run:

```bash
docker run --rm -v "/your/local/folder:/data" soultools/overview:latest --folder /data
```

You'll get a full file count, directory count, size breakdown, and extension summary in under a second.

---

## Why Soul

Python and Bash work fine at small scale. At 10K, 100K, or 1M files they break down — slow execution, complex error handling, runtime dependencies in every environment.

Soul is built for exactly this problem: filesystem tasks that need to be fast, explicit, and deployable anywhere.

## Benchmarks

Reproducible suite: [`bench/`](bench/README.md). It generates a deterministic dataset, **checks every tool's answer against a reference before timing it** (scan results must match exactly; backup destinations are SHA-256-checked), runs the tools in interleaved rounds and reports median, min, max and standard deviation.

```bash
python3 bench/run.py --files 20000 --runs 7
```

**Reference run** (2 vCPU Intel Xeon 2.1 GHz VM, Linux 6.18, 8 GB RAM, tmpfs so there is no disk cost, warm cache, 20,000 small files, 7 runs, Go 1.24, Python 3.13; Soul built with `gcc -O3`, dynamic glibc):

| Task | Soul | Go sequential | Go 4 workers | Python | Other |
|---|---|---|---|---|---|
| Scan 20K files (median) | **0.033 s** | 0.050 s | 0.035 s | 0.067 s | `find`+`awk` 0.056 s |
| Incremental backup, 5% changed (median) | **0.123 s** | 0.134 s | 0.145 s | 0.206 s | — |
| First (full) backup (median) | 0.680 s | 0.593 s | 0.405 s | 0.820 s | `cp -a` 0.341 s |

What this supports, and what it does not:

- **Scan:** Soul is about 1.5x faster than sequential Go and `find`+`awk`, 2x faster than Python, and level with Go using all cores.
- **Incremental backup:** Soul ties with Go using 4 workers and is 0-25% ahead of sequential Go. This workload does **not** show a 2x advantage over Go.
- **Full copy on tmpfs:** `cp -a` and Go with 4 workers are faster than Soul.
- `soul backup` forks 4 worker processes by itself and verifies copies by hash unless you pass `--no-verify`; pinned to a single process (`SOUL_WORKER_COUNT=1`) it is slower than sequential Go. `bench/README.md` lists every variant, the ext4 and big-file runs, and one result I could not explain (Soul copying 2-5x faster than Go on this VM's ext4 volume).
- **Not covered:** `rsync`, static musl builds, cold cache, NVMe, more than 2 cores.

**Earlier measurements by the author** (different hardware, **not reproduced** by the suite above; they show the same direction but larger gaps than the reference run, so treat them as indicative until re-measured with `bench/run.py` on that machine):

| Task | Bash | Python | Go | **Soul** |
|---|---|---|---|---|
| Scan and analysis, 210K files | 2.4 s | — | 0.71 s | **0.39 s** |
| Incremental backup, ~1,600 files, 1.4 GB | — | 38 s | 4.3 s | **1.9 s** |

(`/usr/bin/time -v`, Linux, NVMe SSD, cold cache, Soul built with `musl-gcc -O3 -static -s`.)

---

## Examples (real output)

All output below was produced by running the tools in this repository.

**Errors are reported with a line number and the build stops:**

```text
$ cat bad.soul
str name = "report
printStr(name)

$ soul build bad.soul
== LEXER ==
ERROR: LEXER ERRORS:
Línea 1: Cadena sin cerrar (empieza en la línea 1)
```

```text
$ soul build bad2.soul        # `break` placed outside any loop
...
ERROR: SEMANTIC ERRORS:
'break' fuera de un bucle
```

**`soul plan` says when it cannot simulate something** (it used to report "LOW (read only)" for a program that deletes files):

```text
$ soul plan unsimulated_delete.soul
[builtin] removeByExt(/nonexistent-soul-test-dir, .log)
...
Risk level: UNKNOWN (plan cannot simulate: removeByExt)

WARNING: this plan is INCOMPLETE. Plan mode cannot simulate the
builtins below, so the real run may do more than is listed above:
  - removeByExt
```

**`analyzer` lists the biggest files largest-first** (it used to return the right files in the wrong order):

```text
$ analyzer --folder demo --top 3
Files:
4
Dirs:
2
Size:
115100

Extensions:
txt -> 1 files, 100 bytes
log -> 1 files, 5000 bytes
bin -> 2 files, 110000 bytes

Top biggest files:
demo/sub/big.bin -> 90000
demo/sub/mid.bin -> 20000
demo/medium.log -> 5000
```

**`delete-bigger-than` requires `--minSize`** (older builds ignored the flag and deleted every non-empty file):

```text
$ delete-bigger-than --folder demo --password CONFIRM_DELETE
ERROR: missing --minSize {bytes}
Password protection confirmed
DONE                              # nothing was deleted: all 4 files are still there

$ delete-bigger-than --folder demo --password CONFIRM_DELETE --minSize 10000
Removing big files...
DONE                              # only big.bin and mid.bin (both > 10000 bytes) were removed
```

The same cases run automatically in `php tests/run.php` (39 tests, in `tests/cases`, `tests/e2e`, `tests/plan`).

---

## Docker image size

With `soul build --docker`, Soul compiles to fully static binaries via musl-gcc and generates a `FROM scratch` Dockerfile — no base OS, no runtime, no layers. (Requires `musl-gcc`, e.g. the `musl-tools` package.) The Go and Python columns are typical sizes, not measured by this repository.

| Tool       | Soul binary | Soul Docker (scratch) | Go (Docker) | Python (Docker) |
|------------|-------------|----------------------|-------------|-----------------|
| hello-world | 16 KB      | 44 KB                | ~2 MB       | ~50 MB          |
| overview   | 17 KB       | 68 KB                | ~4 MB       | ~50 MB          |
| backup     | 22 KB       | 130 KB               | ~6 MB       | ~55 MB          |

---

## What Soul code looks like

Incremental backup of a folder — 6 lines of logic:

```soul
str src = arg("--folderSrc")
str dst = arg("--folderDst")

mkdir(dst)
backup(src, dst)
```

Walk a directory and move its files explicitly:

```soul
diriter it = listdir(src)

while true:
    str name = dir_next(it)
    if name == 0:
        break

    str from = join(src, name)
    str to   = join(dst, name)
    move(from, to)

dir_close(it)

rmdir(src)
```

Delete files by extension with an explicit confirmation gate:

```soul
str folder   = arg("--folder")
str ext      = arg("--ext")
str password = arg("--password")

if startswith(password, "CONFIRM_DELETE"):
    removeByExt(folder, ext)
```

---

## Available tools

The sources of the 13 ready-made tools are in [`library/tools/`](library/tools). Binary sizes are the author's figures for musl builds.

| Tool | Description | Binary size |
|------|-------------|-------------|
| `hello-world` | Smallest possible program | 16 KB |
| `overview` | File count, size, extension breakdown | 17 KB |
| `overview-details` | Files, directories and total size, with extension breakdown | — |
| `analyzer` | Full scan: extensions + biggest files (largest first) | 30 KB |
| `walk` | Recursively list every file in a tree (`--find` to search) | — |
| `backup` | Incremental backup (copy when missing, size differs or source is newer); hash-verified unless `--no-verify` | 22 KB |
| `copy-folder` | Recursive folder copy, 4 parallel workers | 22 KB |
| `move` | Move folder contents | 21 KB |
| `create-files` | Generate files for testing | 17 KB |
| `log` | Walk directory tree and write JSON log | — |
| `delete-by-extension` | Delete files by extension, confirmation-gated | 21 KB |
| `delete-bigger-than` | Delete files bigger than N bytes, confirmation-gated | — |
| `delete-folder` | Remove folder tree, confirmation-gated | — |

Download binaries or Docker images at [soul-run.com/tools](https://soul-run.com/#features).

**Read this before using the delete tools.**

- The "password" is a confirmation flag (`--password CONFIRM_DELETE`, any value that *starts with* that text works), meant to stop accidents. It is **not** a security mechanism.
- `--ext` is a **suffix match on the file name**: `--ext .log` deletes `app.log`, but `--ext log` also deletes `catalog`. Always include the dot.
- `delete-by-extension` and `delete-bigger-than` only remove regular files and do not follow symlinks.
- `delete-bigger-than` requires `--minSize {bytes}` and refuses to run without it. (Before this was fixed the flag was never read and every non-empty file was deleted, so do not use older builds.)
- Run `soul plan` first and test on a copy. Note that `soul plan` cannot yet simulate every builtin; see below.

---

## Key characteristics

- **Filesystem-first** — files, folders and paths are core primitives, not library calls
- **Compiled to native binary** — the generated C is compiled with `gcc -O3`, so there is no interpreter overhead
- **Static builds with musl-gcc** (`soul build --docker`) — single executable, no shared libraries; a plain `soul build` links dynamically against libc
- **Explicit by design** — readable syntax, clear control flow, no hidden behavior
- **Built for Linux** — servers, containers, and CI environments where these workloads run
- **Scratch-ready Docker images** — FROM scratch, no base OS layer

---

## Build your own tools

Soul is not just a toolbox — it's a language you can write in.

The online compiler at [soul-run.com](https://soul-run.com) lets you write Soul code in the browser, compile to a native binary or Docker image, and download it directly. Free to try after registration.

---

## Project status

**Alpha (v0.1).** The language and the runtime are young and the syntax may still change.

- Lexer, parser and analyzer report errors with line numbers (unexpected characters, unterminated strings and comments, bad indentation, unknown types, `break` outside a loop, …); the compiler refuses to build a program it cannot fully translate
- C backend (`gcc -O3`; `musl-gcc -static` for `--docker`)
- The runtime does its filesystem work with direct syscalls on the hot paths (`openat`, `getdents64`, `unlinkat`, …). The generated C still includes and uses libc (`stdio`, `string`, `fork`, …), so "no libc" is not accurate
- 13 tools in `library/tools`
- Test suite: `php tests/run.php` (front-end cases, every library tool, end-to-end builds, `soul plan`, runtime checks)

## `soul plan`

`soul plan <file.soul> [args]` runs the program in a simulator that touches nothing and prints what it would do (copies, moves, deletes, bytes) with a risk level. It currently simulates `copy`, `move`, `mkdir`, `remove`, `rmdir`, `listdir`, `walk`, `stat`, `open`, `write` and `close`, plus the pure helpers needed to follow `if` conditions (`startswith`, `toStr`, …).

For anything else (`removeByExt`, `removeTree`, `backup`, `overview*`, …) the plan is **incomplete**: it says so in a WARNING and reports the risk level as `UNKNOWN` instead of "read only". The simulator is a separate interpreter from the compiled binary, so keep it in mind as a preview, not a guarantee.

---

## Roadmap

- `soul plan` — simulate every builtin (today only the ones listed above) so the preview is complete
- A `delete-older-than` tool (the `removeOlderThan` builtin already exists)
- Extended standard library (string, network primitives)
- Real parallel execution primitives in the language

---

## Links

- Website and docs: [soul-run.com](https://soul-run.com)
- Online compiler: [soul-run.com/register](https://soul-run.com/register)
- Community and issues: [GitHub Discussions](https://github.com/RobeLTDP/soulc/discussions)
