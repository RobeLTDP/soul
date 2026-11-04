# Soul benchmarks

A reproducible comparison of Soul with Go, Python, `find`, `cp` and (when installed) `rsync`.

```bash
python3 bench/run.py                                   # 20,000 small files, 7 runs, all scenarios
python3 bench/run.py --files 20000 --runs 7 --workdir /dev/shm/soulbench   # tmpfs: CPU cost, no disk
python3 bench/run.py --profile big-files --files 1600 --scenario backup-full  # ~1.4 GB, like the README's
python3 bench/run.py --cold                            # drop the page cache before every run (root)
```

Needs `php`, `gcc`, `python3`. `go` is optional (Go baselines are skipped without it), as are `rsync` and
`musl-gcc`. Whatever is missing is listed under "Not run" in the report; nothing is silently dropped.
Results are written to `bench/results/` (git-ignored) as Markdown and JSON.

## What is measured

| Scenario | Task | Soul tool |
|---|---|---|
| `scan` | files, directories, bytes, number of extensions and the biggest file of a tree | `analyzer` |
| `backup-full` | first backup into an empty destination | `backup` |
| `backup-incr` | next-day backup: 5% of files changed (half with a new size, half with the **same size** but new content and a newer mtime) and 1% new | `backup` |

Baselines (sources in [`baselines/`](baselines)): Go (sequential and parallel; `copy_file_range` and plain
read/write variants), Python (`os.scandir`, `shutil.copyfile` and plain read/write), `find` + `awk`, `cp -a`,
`rsync -a`. All backup baselines use the same rule as `soul backup`: copy when the destination is missing, the
size differs, or the source is newer.

## Method

1. **Correctness before speed.** Every tool is run once and its result is compared with a reference: scan
   results must match exactly; after a backup, the destination must have the same files with the same SHA-256 as
   the source. A tool that fails is reported as `INVALID` and gets no time. (While building the suite this caught
   a real bug: `analyzer` returned the right top-10 files in the wrong order.)
2. **Interleaved rounds.** Each round runs every tool once, rotating who goes first. A first version ran all
   repetitions of one tool and then the next; on this VM that made whichever tool ran last 5-10x slower, because
   of disk write-back and throttling that had nothing to do with the tool.
3. Every backup run starts from the same state (destination removed, or restored from a snapshot of
   yesterday's copy) followed by `sync`, none of which is timed.
4. Median, min, max and standard deviation of wall time, plus CPU time (user+sys of all child processes).
   **Look at the standard deviation before trusting a ratio.**
5. Datasets come from [`gen_dataset.py`](gen_dataset.py): fixed seed, same bytes everywhere. `small-files` is
   log-normal sizes with a ~4 KiB median; `big-files` (`--files 1600`) is ~1,600 files / 1.4 GB, the shape of the
   README's backup benchmark.

## Things to know before reading any Soul backup number

- `soul backup` **forks 4 worker processes by itself** whenever it is run directly (`soul_parallel_run(4)` in the
  runtime), whatever the number of cores. Comparing it with a single-threaded tool is not like for like, so the
  report has a variant pinned to one process (`SOUL_WORKER_COUNT=1`) next to the default.
- `soul backup` verifies copies with a hash unless `--no-verify` is given. The re-read is a plain `read` right
  after the write, so it is normally served from the page cache and the cost is small, but both variants are listed.
- Soul does not preserve file mtimes; the Go and Python baselines and `cp -a` do (Go without it was no faster).
- `soul backup` leaves coordination files (`soul_copy_<pid>.ready`, `.dirsready`) in `/tmp` after every run.

## Reference run

Environment: 2 vCPU Intel Xeon @ 2.1 GHz VM, Linux 6.18, 8 GB RAM, ext4 on a virtual disk and tmpfs, warm page
cache, Go 1.24, Python 3.13, gcc 13.3. Soul built with `gcc -O3` (dynamic glibc), **not** `musl-gcc -static`:
`musl-gcc` and `rsync` are not installed on this machine, so neither the README's build configuration nor `rsync`
was measured. 20,000 files, ~245 MB.

### tmpfs (CPU and syscall cost, no disk), 7 runs

#### scan

| Tool | median | min | max | stdev | CPU (median) | vs fastest |
|---|---|---|---|---|---|---|
| soul analyzer | 0.033 s | 0.031 s | 0.037 s | 0.002 | 0.032 s | 1.00x |
| go WalkDir (sequential) | 0.050 s | 0.048 s | 0.067 s | 0.007 | 0.051 s | 1.51x |
| go parallel x4 | 0.035 s | 0.031 s | 0.044 s | 0.005 | 0.056 s | 1.05x |
| python scandir | 0.067 s | 0.065 s | 0.085 s | 0.007 | 0.065 s | 2.03x |
| find + awk | 0.056 s | 0.051 s | 0.064 s | 0.005 | 0.062 s | 1.70x |

#### backup-full (first run, empty destination)

| Tool | median | min | max | stdev | CPU (median) | vs fastest |
|---|---|---|---|---|---|---|
| soul backup (default: forks 4 procs) | 0.680 s | 0.605 s | 1.479 s | 0.311 | 1.186 s | 1.99x |
| soul backup (default, --no-verify) | 0.752 s | 0.632 s | 1.230 s | 0.243 | 1.372 s | 2.20x |
| soul backup, 1 process (SOUL_WORKER_COUNT=1) | 1.146 s | 1.069 s | 2.191 s | 0.521 | 1.136 s | 3.36x |
| soul backup, 4 procs launched externally | 1.155 s | 0.641 s | 1.286 s | 0.293 | 2.117 s | 3.39x |
| go sequential | 0.593 s | 0.502 s | 1.741 s | 0.445 | 0.761 s | 1.74x |
| go x4 workers | 0.405 s | 0.346 s | 1.036 s | 0.248 | 0.718 s | 1.19x |
| go sequential, read/write 64K | 0.607 s | 0.508 s | 1.758 s | 0.491 | 0.787 s | 1.78x |
| go x4 workers, read/write 64K | 0.818 s | 0.378 s | 1.097 s | 0.319 | 1.480 s | 2.40x |
| python sequential | 0.820 s | 0.749 s | 1.554 s | 0.285 | 0.799 s | 2.40x |
| python sequential, read/write 64K | 0.965 s | 0.705 s | 2.196 s | 0.511 | 0.959 s | 2.83x |
| cp -a (always full copy) | 0.341 s | 0.322 s | 0.995 s | 0.247 | 0.335 s | 1.00x |

#### backup-incr (5% changed, 1% new)

| Tool | median | min | max | stdev | CPU (median) | vs fastest |
|---|---|---|---|---|---|---|
| soul backup (default: forks 4 procs) | 0.123 s | 0.109 s | 0.143 s | 0.013 | 0.157 s | 1.00x |
| soul backup (default, --no-verify) | 0.124 s | 0.106 s | 0.178 s | 0.024 | 0.157 s | 1.01x |
| soul backup, 1 process (SOUL_WORKER_COUNT=1) | 0.150 s | 0.136 s | 0.207 s | 0.023 | 0.145 s | 1.22x |
| soul backup, 4 procs launched externally | 0.139 s | 0.110 s | 0.174 s | 0.021 | 0.172 s | 1.13x |
| go sequential | 0.134 s | 0.107 s | 0.184 s | 0.025 | 0.176 s | 1.09x |
| go x4 workers | 0.145 s | 0.114 s | 0.205 s | 0.034 | 0.203 s | 1.18x |
| go sequential, read/write 64K | 0.158 s | 0.124 s | 0.203 s | 0.029 | 0.218 s | 1.28x |
| go x4 workers, read/write 64K | 0.126 s | 0.109 s | 0.233 s | 0.043 | 0.177 s | 1.03x |
| python sequential | 0.206 s | 0.177 s | 0.330 s | 0.065 | 0.194 s | 1.68x |
| python sequential, read/write 64K | 0.264 s | 0.161 s | 0.301 s | 0.055 | 0.259 s | 2.14x |
| cp -a (always full copy) | skipped: cp has no incremental mode (shown only in the full scenario) | | | | | |

### ext4 on a virtual disk, 5 runs

#### backup-full (first run, empty destination)

| Tool | median | min | max | stdev | CPU (median) | vs fastest |
|---|---|---|---|---|---|---|
| soul backup (default: forks 4 procs) | 1.894 s | 1.719 s | 2.149 s | 0.169 | 3.367 s | 1.46x |
| soul backup (default, --no-verify) | 1.894 s | 0.644 s | 1.997 s | 0.560 | 3.409 s | 1.46x |
| soul backup, 1 process (SOUL_WORKER_COUNT=1) | 2.708 s | 1.580 s | 3.112 s | 0.721 | 2.601 s | 2.09x |
| soul backup, 4 procs launched externally | 1.297 s | 0.676 s | 1.975 s | 0.629 | 2.470 s | 1.00x |
| go sequential | 5.934 s | 5.076 s | 7.290 s | 0.851 | 6.618 s | 4.58x |
| go x4 workers | 7.002 s | 5.513 s | 7.287 s | 0.704 | 12.563 s | 5.40x |
| go sequential, read/write 64K | 6.149 s | 5.475 s | 7.223 s | 0.736 | 6.907 s | 4.74x |
| go x4 workers, read/write 64K | 6.744 s | 6.088 s | 6.993 s | 0.347 | 11.982 s | 5.20x |
| python sequential | 8.963 s | 8.278 s | 9.394 s | 0.451 | 8.886 s | 6.91x |
| python sequential, read/write 64K | 9.723 s | 8.479 s | 9.958 s | 0.639 | 9.575 s | 7.50x |
| cp -a (always full copy) | 8.442 s | 7.340 s | 9.032 s | 0.707 | 8.320 s | 6.51x |

#### backup-incr (5% changed, 1% new)

| Tool | median | min | max | stdev | CPU (median) | vs fastest |
|---|---|---|---|---|---|---|
| soul backup (default: forks 4 procs) | 0.216 s | 0.139 s | 0.229 s | 0.036 | 0.209 s | 1.00x |
| soul backup (default, --no-verify) | 0.232 s | 0.216 s | 0.273 s | 0.026 | 0.233 s | 1.07x |
| soul backup, 1 process (SOUL_WORKER_COUNT=1) | 0.394 s | 0.317 s | 0.460 s | 0.060 | 0.250 s | 1.82x |
| soul backup, 4 procs launched externally | 0.246 s | 0.202 s | 0.262 s | 0.025 | 0.244 s | 1.14x |
| go sequential | 0.287 s | 0.256 s | 0.307 s | 0.021 | 0.282 s | 1.33x |
| go x4 workers | 0.222 s | 0.211 s | 0.282 s | 0.028 | 0.298 s | 1.03x |
| go sequential, read/write 64K | 0.264 s | 0.251 s | 0.288 s | 0.016 | 0.284 s | 1.22x |
| go x4 workers, read/write 64K | 0.229 s | 0.200 s | 0.269 s | 0.027 | 0.305 s | 1.06x |
| python sequential | 0.561 s | 0.518 s | 0.723 s | 0.081 | 0.374 s | 2.60x |
| python sequential, read/write 64K | 0.557 s | 0.435 s | 0.667 s | 0.085 | 0.348 s | 2.58x |
| cp -a (always full copy) | skipped: cp has no incremental mode (shown only in the full scenario) | | | | | |

### ~1,600 big files, 1.4 GB, tmpfs, 3 runs (very noisy)

#### backup-full (first run, empty destination)

| Tool | median | min | max | stdev | CPU (median) | vs fastest |
|---|---|---|---|---|---|---|
| soul backup (default: forks 4 procs) | 3.728 s | 2.855 s | 4.395 s | 0.772 | 6.499 s | 9.30x |
| soul backup (default, --no-verify) | 3.516 s | 3.260 s | 4.547 s | 0.681 | 6.708 s | 8.77x |
| soul backup, 1 process (SOUL_WORKER_COUNT=1) | 8.872 s | 8.837 s | 10.270 s | 0.818 | 8.770 s | 22.14x |
| soul backup, 4 procs launched externally | 3.062 s | 2.936 s | 3.199 s | 0.132 | 5.790 s | 7.64x |
| go sequential | 3.213 s | 2.120 s | 3.282 s | 0.652 | 3.239 s | 8.02x |
| go x4 workers | 0.401 s | 0.360 s | 1.188 s | 0.467 | 0.755 s | 1.00x |
| go sequential, read/write 64K | 0.771 s | 0.728 s | 2.409 s | 0.958 | 0.812 s | 1.93x |
| go x4 workers, read/write 64K | 1.701 s | 1.588 s | 1.917 s | 0.167 | 3.277 s | 4.24x |
| python sequential | 4.222 s | 2.886 s | 5.092 s | 1.111 | 4.201 s | 10.54x |
| python sequential, read/write 64K | 1.170 s | 0.858 s | 2.916 s | 1.109 | 1.160 s | 2.92x |
| cp -a (always full copy) | 1.489 s | 0.596 s | 2.023 s | 0.721 | 1.457 s | 3.72x |

## What these numbers support

- **Scan:** Soul is 1.5-1.7x faster than a sequential Go walker and than `find` + `awk`, about 2x faster than
  Python, and level with a Go walker that uses all cores (tmpfs: 0.033 s vs 0.035 s; on the disk volume the
  parallel Go walker was ahead). On 2 vCPUs, that is all it shows.
- **Incremental backup:** Soul's default (4 processes) ties with Go using 4 workers and is 0-25% ahead of
  sequential Go. Pinned to one process it is *slower* than sequential Go (12% on tmpfs, 37% on the disk volume).
  Python is about 2x slower. This workload does **not** show a 2x advantage over Go.
- **Full copy on tmpfs:** `cp -a` and Go with 4 workers are faster than Soul (0.34 s and 0.41 s vs 0.68 s).
- **Full copy on the ext4 volume:** Soul was 2-5x faster than every other tool, including a Go program making the
  same `read`/`write` calls. I could **not** explain this and do not claim it. What I ruled out: the copy
  strategy (Go read/write vs `copy_file_range`), mtime preservation, run order (rounds are interleaved), validity
  (every destination is hash-checked, and repeated runs stay correct), and time-to-durable (Soul still wins
  after a `sync`). What remains plausible: Soul creates all directories before copying files, and moving Go to
  that order made it faster in a small experiment. But this VM's disk is bimodal (the same Go run took 2.9 s once
  and 6.6 s twice), so treat it as unexplained and re-run on your hardware.
- **Big files on tmpfs:** the spread is as large as the medians, so only gross effects count. A single Soul
  process (8.9 s) was far slower than Go (0.8-3.2 s) here, again unexplained.
- **Not covered:** `rsync`, a static musl build, cold cache, NVMe, more than 2 cores. The README's numbers
  (210K files scan, 1.4 GB incremental backup on NVMe with a cold cache) come from other hardware and are neither
  confirmed nor refuted by this run.

## Adding a tool or a scenario

Add a `Tool(...)` entry in `scan_tools()` or `backup_tools()` in [`run.py`](run.py). A scan tool must print
`files=N dirs=N bytes=N exts=N top1=N` (see `baselines/scan.py`) or provide a parser; a backup tool just has to
leave a destination identical to the source. If the tool is slow only because of how the harness runs it, that
is a harness bug: open an issue.
