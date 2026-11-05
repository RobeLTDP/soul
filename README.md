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

Reproducible suite in [`bench/`](bench/README.md): deterministic dataset, every tool's result verified against a reference before timing, interleaved rounds, median/min/max reported.

```bash
python3 bench/run.py --files 20000 --runs 7
```

**Reference run** — 20,000 files, 7 runs, median, 2 vCPU, tmpfs, warm cache:

| Task | **Soul** | Go | Python | `find` + `awk` |
|---|---|---|---|---|
| Folder scan and analysis | **0.033 s** | 0.050 s | 0.067 s | 0.056 s |
| Incremental backup (5% changed) | **0.123 s** | 0.134 s | 0.206 s | — |

Full results for every scenario and variant (full copy, big files, ext4, multi-worker Go) are in [`bench/README.md`](bench/README.md).

**Measured on the author's NVMe machine** (cold cache, `musl-gcc -O3 -static -s`):

| Task | Bash | Python | Go | **Soul** |
|---|---|---|---|---|
| Scan and analysis, 210K files | 2.4 s | — | 0.71 s | **0.39 s** |
| Incremental backup, ~1,600 files, 1.4 GB | — | 38 s | 4.3 s | **1.9 s** |

---

## Examples (real output)

All output below was produced by running the tools in this repository.

**Clear errors with line numbers; the build stops before anything is generated:**

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

**`soul plan` previews a program and flags anything it cannot simulate:**

```text
$ soul plan unsimulated_delete.soul
[builtin] removeByExt(/nonexistent-soul-test-dir, .log)
...
Risk level: UNKNOWN (plan cannot simulate: removeByExt)

WARNING: this plan is INCOMPLETE. Plan mode cannot simulate the
builtins below, so the real run may do more than is listed above:
  - removeByExt
```

**`analyzer` lists the biggest files, largest first:**

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

**`delete-bigger-than` requires `--minSize`:**

```text
$ delete-bigger-than --folder demo --password CONFIRM_DELETE
ERROR: missing --minSize {bytes}
Password protection confirmed
DONE                              # nothing deleted

$ delete-bigger-than --folder demo --password CONFIRM_DELETE --minSize 10000
Removing big files...
DONE                              # big.bin and mid.bin (> 10000 bytes) removed
```

The same cases run automatically in `php tests/run.php` (39 tests, in `tests/cases`, `tests/e2e`, `tests/plan`).

---

## Docker image size

With `soul build --docker`, Soul compiles to fully static binaries via musl-gcc and generates a `FROM scratch` Dockerfile — no base OS, no runtime, no layers. (Requires `musl-gcc`, e.g. the `musl-tools` package.) Go and Python columns show typical sizes.

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

The sources of the 13 ready-made tools are in [`library/tools/`](library/tools). Sizes are for musl builds.

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
- `delete-bigger-than` requires `--minSize {bytes}` and refuses to run without it. Use binaries built from the current source.
- Run `soul plan` first and test on a copy.

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
- The runtime does its filesystem work with direct syscalls on the hot paths (`openat`, `getdents64`, `unlinkat`, …).
- 13 tools in `library/tools`
- Test suite: `php tests/run.php` (front-end cases, every library tool, end-to-end builds, `soul plan`, runtime checks)

## `soul plan`

`soul plan <file.soul> [args]` runs the program in a simulator that touches nothing and prints what it would do (copies, moves, deletes, bytes) with a risk level. It currently simulates `copy`, `move`, `mkdir`, `remove`, `rmdir`, `listdir`, `walk`, `stat`, `open`, `write` and `close`, plus the pure helpers needed to follow `if` conditions (`startswith`, `toStr`, …).

For builtins it does not simulate yet (`removeByExt`, `removeTree`, `backup`, `overview*`, …) the plan prints a WARNING and reports the risk level as `UNKNOWN`.

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
