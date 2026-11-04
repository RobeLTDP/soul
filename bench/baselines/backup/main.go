// backup: incremental folder backup with the same decision rule as `soul backup`:
// copy a file when the destination is missing, its size differs, or the source
// mtime is newer. Copies preserve the source mtime (like rsync -a).
//
//	backup [-workers N] [-rw] SRC DST
//
// By default io.CopyBuffer between two *os.File uses copy_file_range(2), an
// in-kernel copy. With -rw the data goes through a plain read(2)/write(2) loop
// with a 64 KiB buffer, which is what `soul backup` does. Comparing the two
// separates "which copy strategy" from "which language".
package main

import (
	"flag"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sync"
	"sync/atomic"
)

type job struct{ src, dst string }

var useRW bool
var preserveTimes = true

var buf = sync.Pool{New: func() any { b := make([]byte, 1<<20); return &b }}
var rwBuf = sync.Pool{New: func() any { b := make([]byte, 64<<10); return &b }}

// rwCopy copies with explicit read/write calls (no copy_file_range/sendfile).
func rwCopy(out, in *os.File) error {
	b := rwBuf.Get().(*[]byte)
	defer rwBuf.Put(b)
	for {
		n, err := in.Read(*b)
		if n > 0 {
			if _, werr := out.Write((*b)[:n]); werr != nil {
				return werr
			}
		}
		if err == io.EOF {
			return nil
		}
		if err != nil {
			return err
		}
	}
}

func needCopy(src, dst string, si os.FileInfo) bool {
	di, err := os.Stat(dst)
	if err != nil {
		return true
	}
	return si.Size() != di.Size() || si.ModTime().After(di.ModTime())
}

func copyFile(j job, si os.FileInfo) error {
	in, err := os.Open(j.src)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.OpenFile(j.dst, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0o644)
	if err != nil {
		return err
	}
	if useRW {
		err = rwCopy(out, in)
	} else {
		b := buf.Get().(*[]byte)
		_, err = io.CopyBuffer(out, in, *b)
		buf.Put(b)
	}
	if cerr := out.Close(); err == nil {
		err = cerr
	}
	if err != nil {
		return err
	}
	if !preserveTimes {
		return nil
	}
	return os.Chtimes(j.dst, si.ModTime(), si.ModTime())
}

func main() {
	workers := flag.Int("workers", 1, "copy workers")
	flag.BoolVar(&preserveTimes, "times", true, "set the destination mtime to the source mtime (rsync -a does; soul backup does not)")
	flag.BoolVar(&useRW, "rw", false, "plain read/write loop (64 KiB) instead of copy_file_range")
	flag.Parse()
	if flag.NArg() != 2 {
		fmt.Fprintln(os.Stderr, "usage: backup [-workers N] [-rw] SRC DST")
		os.Exit(2)
	}
	src, dst := flag.Arg(0), flag.Arg(1)
	os.MkdirAll(dst, 0o755)

	var copied, failed int64
	jobs := make(chan job, 4096)
	var wg sync.WaitGroup
	for i := 0; i < *workers; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for j := range jobs {
				si, err := os.Stat(j.src)
				if err == nil {
					err = copyFile(j, si)
				}
				if err != nil {
					atomic.AddInt64(&failed, 1)
				} else {
					atomic.AddInt64(&copied, 1)
				}
			}
		}()
	}

	filepath.WalkDir(src, func(p string, d os.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		rel, _ := filepath.Rel(src, p)
		target := filepath.Join(dst, rel)
		if d.IsDir() {
			os.MkdirAll(target, 0o755)
			return nil
		}
		if !d.Type().IsRegular() {
			return nil
		}
		si, err := d.Info()
		if err != nil {
			return nil
		}
		if needCopy(p, target, si) {
			jobs <- job{p, target}
		}
		return nil
	})
	close(jobs)
	wg.Wait()
	fmt.Printf("copied=%d failed=%d\n", copied, failed)
	if failed > 0 {
		os.Exit(1)
	}
}
