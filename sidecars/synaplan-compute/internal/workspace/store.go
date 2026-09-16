package workspace

import (
	"encoding/json"
	"errors"
	"io/fs"
	"mime"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/metadist/synaplan-compute/internal/names"
	"github.com/metadist/synaplan-compute/internal/perm"
	"github.com/metadist/synaplan-compute/internal/safepath"
	"github.com/metadist/synaplan-compute/pkg/contract"
	"github.com/oklog/ulid/v2"
)

// ErrNotFound and ErrNotOwned map to workspace_not_found / workspace_not_owned.
var (
	ErrNotFound = errors.New(contract.ErrWorkspaceNotFound)
	ErrNotOwned = errors.New(contract.ErrWorkspaceNotOwned)
	ErrQuota    = errors.New(contract.ErrWorkspaceQuota)
	ErrBadName  = errors.New(contract.ErrBadFileName)
)

const (
	metaSuffix = ".json"
	dataDir    = "data"
)

// Meta is persisted as <root>/<id>.json, outside the directory that is
// bind-mounted into the sandbox, so a script can never rewrite owner or quota.
// The host path is never serialized into API responses.
type Meta struct {
	ID         string    `json:"id"`
	Owner      string    `json:"owner"`
	QuotaMb    int       `json:"quotaMb"`
	CreatedAt  time.Time `json:"createdAt"`
	LastUsedAt time.Time `json:"lastUsedAt"`
}

// Usage is the public quota view.
type Usage struct {
	UsedMb     int
	QuotaMb    int
	FileCount  int
	LastUsedAt time.Time
}

// FileInfo is a regular-file listing row with a relative path only.
type FileInfo struct {
	Path       string
	Size       int64
	Mime       string
	ModifiedAt time.Time
}

// Store is a directory-backed workspace catalog with opaque ULID ids.
// Layout: <root>/<id>.json (metadata) and <root>/<id>/data (the mounted tree).
type Store struct {
	root  string
	owner *perm.Owner
	mu    sync.Mutex
}

// New creates the root directory if needed. Metadata stays service-private.
func New(root string) (*Store, error) {
	return NewWithOwner(root, nil)
}

// NewWithOwner prepares data directories for the sandbox owner.
func NewWithOwner(root string, owner *perm.Owner) (*Store, error) {
	if err := os.MkdirAll(root, 0o750); err != nil {
		return nil, err
	}
	return &Store{root: root, owner: owner}, nil
}

// Create allocates a ULID workspace owned by owner.
func (s *Store) Create(owner string, quotaMb int) (*Meta, error) {
	if owner == "" {
		return nil, errors.New(contract.ErrMissingOwner)
	}
	if quotaMb <= 0 {
		quotaMb = 512
	}
	id := ulid.Make().String()
	meta := &Meta{
		ID:         id,
		Owner:      owner,
		QuotaMb:    quotaMb,
		CreatedAt:  time.Now().UTC(),
		LastUsedAt: time.Now().UTC(),
	}
	if err := os.MkdirAll(s.idDir(id), 0o750); err != nil {
		return nil, err
	}
	if err := s.owner.MkdirAll(s.hostPath(id)); err != nil {
		return nil, err
	}
	if err := s.writeMeta(meta); err != nil {
		return nil, err
	}
	return meta, nil
}

// Get loads metadata by opaque id.
func (s *Store) Get(id string) (*Meta, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.readMeta(id)
}

// AssertOwner returns ErrNotFound or ErrNotOwned.
func (s *Store) AssertOwner(id, owner string) (*Meta, error) {
	meta, err := s.Get(id)
	if err != nil {
		return nil, err
	}
	if meta.Owner != owner {
		return nil, ErrNotOwned
	}
	return meta, nil
}

// HostPath is the data directory the runner bind-mounts. Never returned over HTTP.
func (s *Store) HostPath(id string) string {
	return s.hostPath(id)
}

// Root is the store directory; used to assert bind sources stay under it.
func (s *Store) Root() string { return s.root }

func (s *Store) idDir(id string) string {
	return filepath.Join(s.root, id)
}

func (s *Store) hostPath(id string) string {
	return filepath.Join(s.idDir(id), dataDir)
}

func (s *Store) metaPath(id string) string {
	return filepath.Join(s.root, id+metaSuffix)
}

// Usage recomputes size from the data tree.
func (s *Store) Usage(id string) (Usage, error) {
	meta, err := s.Get(id)
	if err != nil {
		return Usage{}, err
	}
	used, files := s.walkSize(id)
	usedMb := int((used + 1024*1024 - 1) / (1024 * 1024))
	return Usage{UsedMb: usedMb, QuotaMb: meta.QuotaMb, FileCount: files, LastUsedAt: meta.LastUsedAt}, nil
}

// WouldExceed reports whether additional bytes would pass quotaMb.
func (s *Store) WouldExceed(id string, additional int64) (bool, error) {
	meta, err := s.Get(id)
	if err != nil {
		return false, err
	}
	used, _ := s.walkSize(id)
	capBytes := int64(meta.QuotaMb) * 1024 * 1024
	return used+additional > capBytes, nil
}

// walkSize approximates disk use of the data tree. Every directory, regular
// file and symlink costs at least one 4 KiB block so empty files and empty
// folders cannot exhaust inodes for free; regular files add their extra
// bytes on top. WalkDir never follows symlinks.
func (s *Store) walkSize(id string) (int64, int) {
	const block = 4096
	var used int64
	var files int
	base := s.hostPath(id)
	_ = filepath.WalkDir(base, func(path string, d fs.DirEntry, err error) error {
		if err != nil || path == base {
			return nil
		}
		used += block
		if d.IsDir() {
			return nil
		}
		info, err := d.Info()
		if err == nil && info.Mode().IsRegular() && info.Size() > block {
			used += info.Size() - block
		}
		files++
		return nil
	})
	return used, files
}

// OverQuota reports whether the data tree is larger than the quota right now.
// The pre-run check only sees uploaded bytes; a script can write freely to
// /workspace while it runs, so the server calls this once the container is
// gone.
func (s *Store) OverQuota(id string) (bool, error) {
	meta, err := s.Get(id)
	if err != nil {
		return false, err
	}
	used, _ := s.walkSize(id)
	return used > int64(meta.QuotaMb)*1024*1024, nil
}

// Snapshot returns the set of non-directory entries (relative, slash-separated)
// present in the data tree. Taken before a run, it is what RemoveNewEntries
// keeps when that run blows the quota.
func (s *Store) Snapshot(id string) map[string]struct{} {
	base := s.hostPath(id)
	out := map[string]struct{}{}
	_ = filepath.WalkDir(base, func(path string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return nil
		}
		if rel, err := filepath.Rel(base, path); err == nil {
			out[filepath.ToSlash(rel)] = struct{}{}
		}
		return nil
	})
	return out
}

// RemoveNewEntries deletes every non-directory entry that is not in keep and
// reports how many it removed. WalkDir never follows symlinks, so a symlink
// the script planted is removed as an entry, not traversed. Called only
// while no container has the tree mounted.
func (s *Store) RemoveNewEntries(id string, keep map[string]struct{}) int {
	base := s.hostPath(id)
	removed := 0
	_ = filepath.WalkDir(base, func(path string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return nil
		}
		rel, err := filepath.Rel(base, path)
		if err != nil {
			return nil
		}
		if _, kept := keep[filepath.ToSlash(rel)]; kept {
			return nil
		}
		if os.Remove(path) == nil {
			removed++
		}
		return nil
	})
	return removed
}

// Listing bounds: a script can create arbitrarily deep or wide trees, and the
// response must stay small enough for one page.
const (
	listMaxDepth = 8
	listMaxFiles = 2000
)

// ListFiles returns regular files under rel (relative, sanitized), walking
// nested folders so a file at reports/january.csv is listed with that path.
// Symlinks are never followed or listed; the walk stops at listMaxDepth
// levels and listMaxFiles entries.
func (s *Store) ListFiles(id, rel string) ([]FileInfo, error) {
	if _, err := s.Get(id); err != nil {
		return nil, err
	}
	clean, ok := names.RelPath(rel)
	if !ok {
		return nil, ErrBadName
	}
	base := s.hostPath(id)
	out := make([]FileInfo, 0)
	if err := s.walkFiles(base, clean, 0, &out); err != nil {
		if os.IsNotExist(err) {
			return []FileInfo{}, nil
		}
		if errors.Is(err, safepath.ErrSymlink) || errors.Is(err, safepath.ErrNotDir) {
			return nil, ErrBadName
		}
		return nil, err
	}
	return out, nil
}

// walkFiles appends the regular files under dir (relative to base) and
// recurses into real sub-directories. Only the top-level ReadDir error is
// surfaced: a sub-folder that vanishes mid-walk is skipped, not fatal.
func (s *Store) walkFiles(base, dir string, depth int, out *[]FileInfo) error {
	entries, err := safepath.ReadDir(base, filepath.FromSlash(dir))
	if err != nil {
		return err
	}
	for _, e := range entries {
		if len(*out) >= listMaxFiles {
			return nil
		}
		if e.Symlink || e.Info == nil {
			continue
		}
		relPath := e.Name
		if dir != "" {
			relPath = dir + "/" + e.Name
		}
		if e.Info.IsDir() {
			if depth < listMaxDepth {
				_ = s.walkFiles(base, relPath, depth+1, out)
			}
			continue
		}
		if !e.Info.Mode().IsRegular() {
			continue
		}
		f, st, err := safepath.OpenFile(base, filepath.FromSlash(relPath))
		if err != nil {
			continue
		}
		m := sniffOpen(f, e.Name)
		_ = f.Close()
		*out = append(*out, FileInfo{
			Path:       relPath,
			Size:       st.Size(),
			Mime:       m,
			ModifiedAt: st.ModTime().UTC(),
		})
	}
	return nil
}

// OpenFile opens a regular file with a sanitized relative path. Every path
// component is Lstat-checked; the file is opened with O_NOFOLLOW.
func (s *Store) OpenFile(id, rel string) (*os.File, FileInfo, error) {
	if _, err := s.Get(id); err != nil {
		return nil, FileInfo{}, err
	}
	clean, ok := names.RelPath(rel)
	if !ok || clean == "" {
		return nil, FileInfo{}, ErrBadName
	}
	f, st, err := safepath.OpenFile(s.hostPath(id), filepath.FromSlash(clean))
	if err != nil {
		if errors.Is(err, safepath.ErrSymlink) || errors.Is(err, safepath.ErrNotRegular) || errors.Is(err, safepath.ErrNotDir) {
			return nil, FileInfo{}, ErrBadName
		}
		return nil, FileInfo{}, err
	}
	m := sniffOpen(f, clean)
	if _, err := f.Seek(0, 0); err != nil {
		_ = f.Close()
		return nil, FileInfo{}, err
	}
	return f, FileInfo{Path: clean, Size: st.Size(), Mime: m, ModifiedAt: st.ModTime().UTC()}, nil
}

// TouchLastUsed updates lastUsedAt.
func (s *Store) TouchLastUsed(id string) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	meta, err := s.readMeta(id)
	if err != nil {
		return err
	}
	meta.LastUsedAt = time.Now().UTC()
	return s.writeMetaLocked(meta)
}

// Delete removes the data tree and metadata.
func (s *Store) Delete(id string) error {
	if _, err := s.Get(id); err != nil {
		return err
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	if err := os.RemoveAll(s.idDir(id)); err != nil {
		return err
	}
	return os.Remove(s.metaPath(id))
}

func (s *Store) readMeta(id string) (*Meta, error) {
	if !validID(id) {
		return nil, ErrNotFound
	}
	b, err := os.ReadFile(s.metaPath(id))
	if err != nil {
		return nil, ErrNotFound
	}
	var m Meta
	if err := json.Unmarshal(b, &m); err != nil {
		return nil, ErrNotFound
	}
	if m.ID != id {
		return nil, ErrNotFound
	}
	return &m, nil
}

func validID(id string) bool {
	if len(id) != 26 {
		return false
	}
	_, err := ulid.ParseStrict(id)
	return err == nil
}

func (s *Store) writeMeta(m *Meta) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.writeMetaLocked(m)
}

func (s *Store) writeMetaLocked(m *Meta) error {
	b, err := json.Marshal(m)
	if err != nil {
		return err
	}
	return os.WriteFile(s.metaPath(m.ID), b, 0o640)
}

func sniffOpen(f *os.File, name string) string {
	if ext := strings.ToLower(filepath.Ext(name)); ext != "" {
		if m := mime.TypeByExtension(ext); m != "" {
			return strings.TrimSpace(strings.Split(m, ";")[0])
		}
	}
	buf := make([]byte, 512)
	n, _ := f.Read(buf)
	return strings.TrimSpace(strings.Split(http.DetectContentType(buf[:n]), ";")[0])
}
