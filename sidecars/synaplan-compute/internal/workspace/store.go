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
	"github.com/metadist/synaplan-compute/pkg/contract"
	"github.com/oklog/ulid/v2"
)

// ErrNotFound and ErrNotOwned map to workspace_not_found / workspace_not_owned.
var (
	ErrNotFound = errors.New(contract.ErrWorkspaceNotFound)
	ErrNotOwned = errors.New(contract.ErrWorkspaceNotOwned)
	ErrQuota    = errors.New(contract.ErrWorkspaceQuota)
)

// Meta is persisted next to the workspace directory. The host path is never
// serialized into API responses.
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
type Store struct {
	root string
	mu   sync.Mutex
}

// New creates the root directory if needed.
func New(root string) (*Store, error) {
	if err := os.MkdirAll(root, 0o750); err != nil {
		return nil, err
	}
	return &Store{root: root}, nil
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
	dir := s.hostPath(id)
	if err := os.MkdirAll(dir, 0o750); err != nil {
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

// HostPath is internal (runner bind-mount). Never returned over HTTP.
func (s *Store) HostPath(id string) string {
	return s.hostPath(id)
}

func (s *Store) hostPath(id string) string {
	return filepath.Join(s.root, id)
}

// Usage recomputes size from the directory tree.
func (s *Store) Usage(id string) (Usage, error) {
	meta, err := s.Get(id)
	if err != nil {
		return Usage{}, err
	}
	var used int64
	var files int
	_ = filepath.WalkDir(s.hostPath(id), func(path string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() || d.Name() == "meta.json" {
			return nil
		}
		info, err := d.Info()
		if err != nil || !info.Mode().IsRegular() {
			return nil
		}
		used += info.Size()
		files++
		return nil
	})
	usedMb := int((used + 1024*1024 - 1) / (1024 * 1024))
	if used == 0 {
		usedMb = 0
	}
	return Usage{UsedMb: usedMb, QuotaMb: meta.QuotaMb, FileCount: files, LastUsedAt: meta.LastUsedAt}, nil
}

// WouldExceed reports whether additional bytes would pass quotaMb.
func (s *Store) WouldExceed(id string, additional int64) (bool, error) {
	meta, err := s.Get(id)
	if err != nil {
		return false, err
	}
	var used int64
	_ = filepath.WalkDir(s.hostPath(id), func(path string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() || d.Name() == "meta.json" {
			return nil
		}
		info, err := d.Info()
		if err != nil || !info.Mode().IsRegular() {
			return nil
		}
		used += info.Size()
		return nil
	})
	capBytes := int64(meta.QuotaMb) * 1024 * 1024
	return used+additional > capBytes, nil
}

// ListFiles returns regular files under rel (relative, sanitized).
func (s *Store) ListFiles(id, rel string) ([]FileInfo, error) {
	if _, err := s.Get(id); err != nil {
		return nil, err
	}
	clean, ok := names.RelPath(rel)
	if !ok {
		return nil, errors.New(contract.ErrBadFileName)
	}
	base := s.hostPath(id)
	dir := base
	if clean != "" {
		dir = filepath.Join(base, filepath.FromSlash(clean))
	}
	entries, err := os.ReadDir(dir)
	if err != nil {
		if os.IsNotExist(err) {
			return []FileInfo{}, nil
		}
		return nil, err
	}
	out := make([]FileInfo, 0, len(entries))
	for _, e := range entries {
		if e.Name() == "meta.json" || e.IsDir() {
			continue
		}
		info, err := e.Info()
		if err != nil || !info.Mode().IsRegular() {
			continue
		}
		relPath := e.Name()
		if clean != "" {
			relPath = clean + "/" + e.Name()
		}
		out = append(out, FileInfo{
			Path:       relPath,
			Size:       info.Size(),
			Mime:       sniff(filepath.Join(dir, e.Name())),
			ModifiedAt: info.ModTime().UTC(),
		})
	}
	return out, nil
}

// OpenFile opens a regular file with a sanitized relative path.
func (s *Store) OpenFile(id, rel string) (*os.File, FileInfo, error) {
	if _, err := s.Get(id); err != nil {
		return nil, FileInfo{}, err
	}
	clean, ok := names.RelPath(rel)
	if !ok || clean == "" {
		return nil, FileInfo{}, errors.New(contract.ErrBadFileName)
	}
	full := filepath.Join(s.hostPath(id), filepath.FromSlash(clean))
	st, err := os.Lstat(full)
	if err != nil {
		return nil, FileInfo{}, err
	}
	if !st.Mode().IsRegular() {
		return nil, FileInfo{}, errors.New(contract.ErrBadFileName)
	}
	f, err := os.Open(full)
	if err != nil {
		return nil, FileInfo{}, err
	}
	return f, FileInfo{Path: clean, Size: st.Size(), Mime: sniff(full), ModifiedAt: st.ModTime().UTC()}, nil
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

// Delete removes directory and metadata.
func (s *Store) Delete(id string) error {
	if _, err := s.Get(id); err != nil {
		return err
	}
	return os.RemoveAll(s.hostPath(id))
}

func (s *Store) readMeta(id string) (*Meta, error) {
	if id == "" || strings.Contains(id, "/") || strings.Contains(id, "..") {
		return nil, ErrNotFound
	}
	b, err := os.ReadFile(filepath.Join(s.hostPath(id), "meta.json"))
	if err != nil {
		return nil, ErrNotFound
	}
	var m Meta
	if err := json.Unmarshal(b, &m); err != nil {
		return nil, ErrNotFound
	}
	return &m, nil
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
	return os.WriteFile(filepath.Join(s.hostPath(m.ID), "meta.json"), b, 0o640)
}

func sniff(path string) string {
	ext := strings.ToLower(filepath.Ext(path))
	if ext != "" {
		if m := mime.TypeByExtension(ext); m != "" {
			return m
		}
	}
	f, err := os.Open(path)
	if err != nil {
		return "application/octet-stream"
	}
	defer f.Close()
	buf := make([]byte, 512)
	n, _ := f.Read(buf)
	return http.DetectContentType(buf[:n])
}
