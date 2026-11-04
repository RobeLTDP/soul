#!/bin/sh
# scan: same output as baselines/scan/main.go, using find + awk (the "just use
# the shell" baseline).
[ $# -eq 1 ] || { echo "usage: scan_find.sh DIR" >&2; exit 2; }
dirs=$(find "$1" -type d | wc -l)
find "$1" -type f -printf '%s %f\n' | awk -v dirs="$dirs" '
  { files++; bytes += $1; if ($1 > top1) top1 = $1
    n = split($2, p, "."); if (n > 1 && p[1] != "" && p[n] != "") e[p[n]] = 1 }
  END { for (k in e) ne++
        printf "files=%d dirs=%d bytes=%d exts=%d top1=%d\n", files, dirs, bytes, ne, top1 }'
