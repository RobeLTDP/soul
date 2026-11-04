#!/usr/bin/env python3
"""scan: same output as baselines/scan/main.go, using os.scandir recursion."""
import os
import sys


def main(root):
    files = dirs = nbytes = top1 = 0
    exts = set()
    stack = [root]
    while stack:
        d = stack.pop()
        dirs += 1
        try:
            it = os.scandir(d)
        except OSError:
            continue
        with it:
            for e in it:
                if e.is_dir(follow_symlinks=False):
                    stack.append(e.path)
                elif e.is_file(follow_symlinks=False):
                    size = e.stat(follow_symlinks=False).st_size
                    files += 1
                    nbytes += size
                    if size > top1:
                        top1 = size
                    i = e.name.rfind(".")
                    if 0 < i < len(e.name) - 1:
                        exts.add(e.name[i + 1:])
    print("files=%d dirs=%d bytes=%d exts=%d top1=%d" % (files, dirs, nbytes, len(exts), top1))


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit("usage: scan.py DIR")
    main(sys.argv[1])
