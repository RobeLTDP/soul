#!/usr/bin/env python3
"""backup: same decision rule and behaviour as baselines/backup/main.go (sequential)."""
import os
import shutil
import sys


def rw_copy(src, dst, bufsize=64 * 1024):
    """Plain read/write loop (what `soul backup` does) instead of sendfile."""
    with open(src, "rb", buffering=0) as i, open(dst, "wb", buffering=0) as o:
        b = bytearray(bufsize)
        mv = memoryview(b)
        while True:
            n = i.readinto(b)
            if not n:
                break
            o.write(mv[:n])


def main(src, dst, rw=False):
    os.makedirs(dst, exist_ok=True)
    copied = failed = 0
    stack = [""]
    while stack:
        rel = stack.pop()
        sd = os.path.join(src, rel) if rel else src
        dd = os.path.join(dst, rel) if rel else dst
        os.makedirs(dd, exist_ok=True)
        with os.scandir(sd) as it:
            for e in it:
                r = os.path.join(rel, e.name) if rel else e.name
                if e.is_dir(follow_symlinks=False):
                    stack.append(r)
                    continue
                if not e.is_file(follow_symlinks=False):
                    continue
                st = e.stat(follow_symlinks=False)
                target = os.path.join(dst, r)
                try:
                    dt = os.stat(target)
                    need = st.st_size != dt.st_size or st.st_mtime_ns > dt.st_mtime_ns
                except FileNotFoundError:
                    need = True
                if not need:
                    continue
                try:
                    if rw:
                        rw_copy(e.path, target)
                    else:
                        shutil.copyfile(e.path, target)
                    os.utime(target, ns=(st.st_atime_ns, st.st_mtime_ns))
                    copied += 1
                except OSError:
                    failed += 1
    print("copied=%d failed=%d" % (copied, failed))
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    args = [x for x in sys.argv[1:] if x != "--rw"]
    if len(args) != 2:
        sys.exit("usage: backup.py [--rw] SRC DST")
    main(args[0], args[1], rw="--rw" in sys.argv[1:])
