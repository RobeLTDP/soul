# Soul — Compiled Language for Filesystem Automation

**Write filesystem tools in a few lines. Compile to a standalone binary. Run anywhere Linux runs.**

Soul is a compiled language designed specifically for filesystem operations — backup, cleanup, sync, analysis. It produces ultralight static binaries (16–30 KB) with no runtime, no interpreter, and no dependencies.

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

**Benchmark — full folder scan and analysis, 210K files:**

| Tool   | Time   |
|--------|--------|
| Bash   | 2.4s   |
| Go     | 0.71s  |
| **Soul**   | **0.39s**  |

**Benchmark — incremental backup, ~1,600 files, 1.4 GB:**

| Tool   | Time   |
|--------|--------|
| Python | 38s    |
| Go     | 4.3s   |
| **Soul**   | **1.9s**   |

Measured with `/usr/bin/time -v` on Linux, NVMe SSD, cold cache. Soul binaries compiled with `musl-gcc -O3 -static -s`.

---

## Docker image size

Soul compiles to fully static binaries via musl-gcc. Docker images use `FROM scratch` — no base OS, no runtime, no layers.

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

Walk a directory and copy files explicitly:

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

Ready-to-run binaries and Docker images for the most common filesystem tasks:

| Tool | Description | Binary size |
|------|-------------|-------------|
| `overview` | File count, size, extension breakdown | 17 KB |
| `analyzer` | Full scan: extensions + biggest files | 30 KB |
| `backup` | Incremental backup with parallel workers | 22 KB |
| `copy-folder` | Recursive folder copy, 4 parallel workers | 22 KB |
| `move` | Move folder contents | 21 KB |
| `create-files` | Generate files for testing | 17 KB |
| `log` | Walk directory tree and write JSON log | — |
| `delete-by-extension` | Delete files by extension, password-gated | 21 KB |
| `delete-older-than` | Delete files older than N days | — |
| `delete-folder` | Remove folder tree, password-gated | — |

Download binaries or Docker images at [soul-run.com/tools](https://soul-run.com/#features).

---

## Key characteristics

- **Filesystem-first** — files, folders and paths are core primitives, not library calls
- **Compiled to native binary** — near C-level performance, no interpreter overhead
- **Static linking via musl-gcc** — single executable, zero dependencies
- **Explicit by design** — readable syntax, clear control flow, no hidden behavior
- **Built for Linux** — servers, containers, and CI environments where these workloads run
- **Scratch-ready Docker images** — FROM scratch, no base OS layer

---

## Build your own tools

Soul is not just a toolbox — it's a language you can write in.

The online compiler at [soul-run.com](https://soul-run.com) lets you write Soul code in the browser, compile to a native binary or Docker image, and download it directly. Free to try after registration.

---

## Project status

- Stable lexer, parser and compiler (C backend)
- Custom runtime (syscall-based, no libc dependency)
- Syntax stable at v0.1
- Production-tested on large filesystem workloads
- 13 tools available, binary + Docker

---

## Roadmap

- `soul plan` — preview execution with no side effects
- Extended standard library (string, network primitives)
- Real parallel execution primitives in the language

---

## Links

- Website and docs: [soul-run.com](https://soul-run.com)
- Online compiler: [soul-run.com/register](https://soul-run.com/register)
- Community and issues: [GitHub Discussions](https://github.com/RobeLTDP/soulc/discussions)
