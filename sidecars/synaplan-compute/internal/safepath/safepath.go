// Package safepath resolves untrusted relative paths under a trusted root
// without ever following a symlink. Sandboxed scripts can plant symlinks in
// /work, /out, or /workspace; every component including root is opened with
// O_NOFOLLOW (openat for nested names) so a raced directory replace cannot
// walk out. The final descriptor is Stat-checked after open.
package safepath

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"syscall"

	"golang.org/x/sys/unix"
)

// ErrSymlink is returned when any component including root is a symlink.
var ErrSymlink = errors.New("safepath: symlink refused")

// ErrNotRegular is returned when the final entry is not a regular file.
var ErrNotRegular = errors.New("safepath: not a regular file")

// ErrNotDir is returned when a directory was expected.
var ErrNotDir = errors.New("safepath: not a directory")

func splitRel(rel string) ([]string, error) {
	if rel == "" {
		return nil, nil
	}
	var segs []string
	for _, seg := range strings.Split(filepath.ToSlash(rel), "/") {
		if seg == "" || seg == "." || seg == ".." {
			return nil, os.ErrInvalid
		}
		segs = append(segs, seg)
	}
	return segs, nil
}

func mapOpenErr(err error) error {
	if err == nil {
		return nil
	}
	if errors.Is(err, syscall.ELOOP) || errors.Is(err, syscall.EPERM) {
		return ErrSymlink
	}
	return err
}

// openWalk opens root with O_NOFOLLOW and each rel component with openat +
// O_NOFOLLOW. Intermediate names are opened as directories. The returned
// *os.File owns the final descriptor.
func openWalk(root, rel string) (*os.File, os.FileInfo, error) {
	segs, err := splitRel(rel)
	if err != nil {
		return nil, nil, err
	}
	st, err := os.Lstat(root)
	if err != nil {
		return nil, nil, err
	}
	if st.Mode()&os.ModeSymlink != 0 {
		return nil, nil, ErrSymlink
	}
	flags := syscall.O_RDONLY | syscall.O_CLOEXEC | syscall.O_NOFOLLOW
	if st.IsDir() {
		flags |= syscall.O_DIRECTORY
	} else if len(segs) > 0 {
		return nil, nil, ErrNotDir
	}
	fd, err := syscall.Open(root, flags, 0)
	if err != nil {
		return nil, nil, mapOpenErr(err)
	}
	cur := root
	for i, seg := range segs {
		var lst unix.Stat_t
		if err := unix.Fstatat(fd, seg, &lst, unix.AT_SYMLINK_NOFOLLOW); err != nil {
			_ = syscall.Close(fd)
			return nil, nil, err
		}
		if lst.Mode&unix.S_IFMT == unix.S_IFLNK {
			_ = syscall.Close(fd)
			return nil, nil, ErrSymlink
		}
		nextFlags := syscall.O_RDONLY | syscall.O_CLOEXEC | syscall.O_NOFOLLOW
		if i < len(segs)-1 {
			nextFlags |= syscall.O_DIRECTORY
		}
		next, err := syscall.Openat(fd, seg, nextFlags, 0)
		_ = syscall.Close(fd)
		if err != nil {
			return nil, nil, mapOpenErr(err)
		}
		fd = next
		cur = filepath.Join(cur, seg)
	}
	f := os.NewFile(uintptr(fd), cur)
	info, err := f.Stat()
	if err != nil {
		_ = f.Close()
		return nil, nil, err
	}
	return f, info, nil
}

// Walk opens every component of rel below root without following a symlink
// and returns the joined host path plus the Stat of the final descriptor.
// rel must already be sanitized (no "..", no leading slash); an empty rel
// returns root itself.
func Walk(root, rel string) (string, os.FileInfo, error) {
	f, st, err := openWalk(root, rel)
	if err != nil {
		return "", nil, err
	}
	name := f.Name()
	_ = f.Close()
	return name, st, nil
}

// OpenFile walks rel under root, refuses symlinks, and returns an open
// regular-file descriptor that was never reached through a symlink.
func OpenFile(root, rel string) (*os.File, os.FileInfo, error) {
	f, st, err := openWalk(root, rel)
	if err != nil {
		return nil, nil, err
	}
	if !st.Mode().IsRegular() {
		_ = f.Close()
		return nil, nil, ErrNotRegular
	}
	return f, st, nil
}

// Entry is one directory member described without following it.
type Entry struct {
	Name    string
	Info    os.FileInfo
	Symlink bool
}

// ReadDir walks rel under root and describes every member without following
// a symlink. Symlinked members are flagged so callers can skip them.
func ReadDir(root, rel string) ([]Entry, error) {
	dir, st, err := openWalk(root, rel)
	if err != nil {
		return nil, err
	}
	defer dir.Close()
	if !st.IsDir() {
		return nil, ErrNotDir
	}
	names, err := dir.Readdirnames(-1)
	if err != nil {
		return nil, err
	}
	dirfd := int(dir.Fd())
	out := make([]Entry, 0, len(names))
	for _, name := range names {
		cfd, err := syscall.Openat(dirfd, name, syscall.O_RDONLY|syscall.O_CLOEXEC|syscall.O_NOFOLLOW, 0)
		if err != nil {
			if errors.Is(err, syscall.ELOOP) || errors.Is(err, syscall.EPERM) {
				out = append(out, Entry{Name: name, Symlink: true})
			}
			continue
		}
		cf := os.NewFile(uintptr(cfd), name)
		info, err := cf.Stat()
		_ = cf.Close()
		if err != nil {
			continue
		}
		out = append(out, Entry{Name: name, Info: info, Symlink: info.Mode()&os.ModeSymlink != 0})
	}
	return out, nil
}
