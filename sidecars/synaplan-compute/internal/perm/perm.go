// Package perm creates the host directories and files the sandbox user must
// be able to read and write. The service runs as an unprivileged uid
// (distroless nonroot) and the sandbox as COMPUTE_SANDBOX_UID. Successful
// chown sets owner=sandbox uid and group=the service process gid so both
// sides keep 0770/0660 access. Without CAP_CHOWN the only portable way to
// share is world-accessible modes; that fallback is logged once at startup.
package perm

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

const (
	dirShared     = os.FileMode(0o770)
	fileShared    = os.FileMode(0o660)
	dirFallback   = os.FileMode(0o777)
	fileFallback  = os.FileMode(0o666)
	probeFileName = ".chown-probe"
)

// Owner is the sandbox identity host paths are prepared for. A nil Owner
// keeps service-private modes (0o750/0o640) and never chowns.
type Owner struct {
	UID   int
	GID   int
	chown bool
}

// New returns an Owner that will try chown until Probe reports it fails.
func New(uid, gid int) *Owner {
	return &Owner{UID: uid, GID: gid, chown: true}
}

// Probe creates dir, tries to chown a file inside it to the owner, and
// disables chown for the rest of the process lifetime when that fails. The
// returned error is the chown failure, meant for a startup log line.
func (o *Owner) Probe(dir string) error {
	if o == nil {
		return nil
	}
	if err := os.MkdirAll(dir, dirShared); err != nil {
		return err
	}
	p := filepath.Join(dir, probeFileName)
	if err := os.WriteFile(p, nil, fileShared); err != nil {
		return err
	}
	defer os.Remove(p)
	if err := os.Chown(p, o.UID, os.Getgid()); err != nil {
		o.chown = false
		return fmt.Errorf("chown to %d:%d (sandbox uid, service gid) failed (%v); falling back to world-writable scratch modes", o.UID, os.Getgid(), err)
	}
	o.chown = true
	return nil
}

// ChownEnabled reports whether ownership is transferred rather than opened up.
func (o *Owner) ChownEnabled() bool { return o != nil && o.chown }

// MkdirAll creates path (and parents) so the sandbox can enter and write it.
func (o *Owner) MkdirAll(path string) error {
	if o == nil {
		return os.MkdirAll(path, 0o750)
	}
	if err := os.MkdirAll(path, dirShared); err != nil {
		return err
	}
	return o.apply(path, dirShared, dirFallback)
}

// WriteFile writes data so the sandbox can read (and overwrite) it.
func (o *Owner) WriteFile(path string, data []byte) error {
	if o == nil {
		return os.WriteFile(path, data, 0o640)
	}
	if err := os.WriteFile(path, data, fileShared); err != nil {
		return err
	}
	return o.apply(path, fileShared, fileFallback)
}

func (o *Owner) apply(path string, shared, fallback os.FileMode) error {
	if err := os.Chmod(path, shared); err != nil {
		return err
	}
	if o.chown {
		// Owner bits are for the sandbox uid; group bits stay on the
		// service so artefact/workspace listing still works after chown.
		if err := os.Chown(path, o.UID, os.Getgid()); err == nil {
			return nil
		} else if !errors.Is(err, os.ErrPermission) {
			return err
		}
	}
	return os.Chmod(path, fallback)
}

// String renders uid:gid for the container User field.
func (o *Owner) String() string {
	if o == nil {
		return ""
	}
	return fmt.Sprintf("%d:%d", o.UID, o.GID)
}
