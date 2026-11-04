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

**Benchmark — full folder scan and analysis, 210K files** *(author's measurement)*:

| Tool   | Time   |
|--------|--------|
| Bash   | 2.4s   |
| Go     | 0.71s  |
| **Soul**   | **0.39s**  |

**Benchmark — incremental backup, ~1,600 files, 1.4 GB** *(author's measurement)*:

| Tool   | Time   |
|--------|--------|
| Python | 38s    |
| Go     | 4.3s   |
| **Soul**   | **1.9s**   |

Measured with `/usr/bin/time -v` on Linux, NVMe SSD, cold cache. Soul binaries compiled with `musl-gcc -O3 -static -s`.

These numbers come from the author's own datasets and machine. To measure on yours, use the reproducible suite in [`bench/`](bench/README.md): it generates a deterministic dataset, **checks every tool's answer against a reference before timing it**, and compares Soul with Go, Python, `find`, `rsync` and `cp`:

```bash
python3 bench/run.py --files 20000 --runs 7
```

Two things to know when reading any backup comparison: `soul backup` verifies copies by hash unless you pass `--no-verify`, and a single `soul` process uses one worker — multi-worker runs are launched with `soul run --parallel=N`. `bench/README.md` has a reference run, including where Soul is *not* the fastest.

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
