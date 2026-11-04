// scan: count files, dirs, bytes, extensions and the biggest file under a tree.
// Output (one line, same for every baseline):
//
//	files=N dirs=N bytes=N exts=N top1=N
//
// Directories include the root, like `soul analyzer`.
//
//	scan DIR            sequential filepath.WalkDir (idiomatic Go)
//	scan -workers N DIR one goroutine pool walking directories in parallel
package main

import (
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
)

type stats struct {
	files, dirs, bytes, top1 int64
	exts                     map[string]struct{}
}

func ext(name string) string {
	i := strings.LastIndexByte(name, '.')
	if i <= 0 || i == len(name)-1 {
		return ""
	}
	return name[i+1:]
}

func (s *stats) addFile(name string, size int64) {
	s.files++
	s.bytes += size
	if size > s.top1 {
		s.top1 = size
	}
	if e := ext(name); e != "" {
		s.exts[e] = struct{}{}
	}
}

func sequential(root string) *stats {
	s := &stats{exts: map[string]struct{}{}}
	filepath.WalkDir(root, func(p string, d os.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if d.IsDir() {
			s.dirs++
			return nil
		}
		if d.Type().IsRegular() {
			if info, err := d.Info(); err == nil {
				s.addFile(d.Name(), info.Size())
			}
		}
		return nil
	})
	return s
}

func parallel(root string, workers int) *stats {
	total := &stats{exts: map[string]struct{}{}}
	var mu sync.Mutex
	var wg sync.WaitGroup
	jobs := make(chan string, 1<<16)
	pending := sync.WaitGroup{}

	worker := func() {
		defer wg.Done()
		local := &stats{exts: map[string]struct{}{}}
		for dir := range jobs {
			entries, err := os.ReadDir(dir)
			if err == nil {
				local.dirs++
				for _, e := range entries {
					if e.IsDir() {
						pending.Add(1)
						select {
						case jobs <- filepath.Join(dir, e.Name()):
						default: // queue full: recurse inline instead of blocking
							sub := parallelInline(filepath.Join(dir, e.Name()))
							merge(local, sub)
							pending.Done()
						}
					} else if e.Type().IsRegular() {
						if info, err := e.Info(); err == nil {
							local.addFile(e.Name(), info.Size())
						}
					}
				}
			}
			pending.Done()
		}
		mu.Lock()
		merge(total, local)
		mu.Unlock()
	}

	pending.Add(1)
	jobs <- root
	for i := 0; i < workers; i++ {
		wg.Add(1)
		go worker()
	}
	pending.Wait()
	close(jobs)
	wg.Wait()
	return total
}

func parallelInline(root string) *stats { return sequential(root) }

func merge(dst, src *stats) {
	dst.files += src.files
	dst.dirs += src.dirs
	dst.bytes += src.bytes
	if src.top1 > dst.top1 {
		dst.top1 = src.top1
	}
	for e := range src.exts {
		dst.exts[e] = struct{}{}
	}
}

func main() {
	workers := flag.Int("workers", 1, "parallel directory readers (1 = sequential WalkDir)")
	flag.Parse()
	if flag.NArg() != 1 {
		fmt.Fprintln(os.Stderr, "usage: scan [-workers N] DIR")
		os.Exit(2)
	}
	var s *stats
	if *workers > 1 {
		s = parallel(flag.Arg(0), *workers)
	} else {
		s = sequential(flag.Arg(0))
	}
	fmt.Printf("files=%d dirs=%d bytes=%d exts=%d top1=%d\n", s.files, s.dirs, s.bytes, len(s.exts), s.top1)
}
