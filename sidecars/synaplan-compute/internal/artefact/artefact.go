// Package artefact lists and streams files from a run's /out directory.
// Results are untrusted: regular files only, no symlink is ever followed
// (safepath), and the MIME allow-list and size cap apply to both listing
// and download so a rejected row can never be fetched.
package artefact

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"mime"
	"net/http"
	"os"
	"path/filepath"
	"strings"

	"github.com/metadist/synaplan-compute/internal/names"
	"github.com/metadist/synaplan-compute/internal/safepath"
	"github.com/metadist/synaplan-compute/pkg/contract"
)

// Item is one /out entry.
type Item struct {
	Name     string
	Size     int64
	Mime     string
	SHA256   string
	Rejected string
}

// Refused is returned by Open for a listed-but-rejected artefact.
type Refused struct {
	Code string
}

func (e *Refused) Error() string { return "artefact refused: " + e.Code }

// ErrNotFound is returned by Open when name is not a regular file in /out.
var ErrNotFound = errors.New("artefact not found")

// List regular files under outDir. Symlinks are omitted. Total size above
// maxBytes marks subsequent files rejected.
func List(outDir string, maxBytes int64, mimeAllow []string) ([]Item, error) {
	entries, err := safepath.ReadDir(outDir, "")
	if err != nil {
		if os.IsNotExist(err) {
			return []Item{}, nil
		}
		return nil, err
	}
	allow := make(map[string]struct{}, len(mimeAllow))
	for _, m := range mimeAllow {
		allow[m] = struct{}{}
	}
	var total int64
	items := make([]Item, 0, len(entries))
	for _, e := range entries {
		if e.Symlink || e.Info == nil || !e.Info.Mode().IsRegular() || !names.FileName(e.Name) {
			continue
		}
		it := Item{Name: e.Name, Size: e.Info.Size()}
		f, st, err := safepath.OpenFile(outDir, e.Name)
		if err != nil {
			continue
		}
		it.Size = st.Size()
		it.Mime = sniffOpen(f, e.Name)
		if sum, err := hashOpen(f); err == nil {
			it.SHA256 = sum
		}
		_ = f.Close()
		if _, ok := allow[it.Mime]; !ok && len(allow) > 0 {
			it.Rejected = contract.ErrMimeNotAllowed
		}
		if maxBytes > 0 && total+it.Size > maxBytes {
			it.Rejected = contract.ReasonOutputLimit
		}
		if it.Rejected == "" {
			total += it.Size
		}
		items = append(items, it)
	}
	return items, nil
}

// Open streams a regular file that List would report as accepted. Symlinks,
// odd names, disallowed MIME types, and files beyond the size cap are refused.
func Open(outDir, name string, maxBytes int64, mimeAllow []string) (*os.File, Item, error) {
	if !names.FileName(name) {
		return nil, Item{}, ErrNotFound
	}
	items, err := List(outDir, maxBytes, mimeAllow)
	if err != nil {
		return nil, Item{}, err
	}
	for _, it := range items {
		if it.Name != name {
			continue
		}
		if it.Rejected != "" {
			return nil, it, &Refused{Code: it.Rejected}
		}
		f, _, err := safepath.OpenFile(outDir, name)
		if err != nil {
			return nil, it, err
		}
		return f, it, nil
	}
	return nil, Item{}, ErrNotFound
}

// TotalBytes sums regular-file sizes directly under each dir without
// following symlinks. Missing dirs count as zero.
func TotalBytes(dirs ...string) int64 {
	var total int64
	for _, dir := range dirs {
		entries, err := safepath.ReadDir(dir, "")
		if err != nil {
			continue
		}
		for _, e := range entries {
			if e.Symlink || e.Info == nil {
				continue
			}
			if e.Info.IsDir() {
				total += TotalBytes(filepath.Join(dir, e.Name))
				continue
			}
			if e.Info.Mode().IsRegular() {
				total += e.Info.Size()
			}
		}
	}
	return total
}

func hashOpen(f *os.File) (string, error) {
	if _, err := f.Seek(0, io.SeekStart); err != nil {
		return "", err
	}
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

func sniffOpen(f *os.File, name string) string {
	if ext := strings.ToLower(filepath.Ext(name)); ext != "" {
		if m := mime.TypeByExtension(ext); m != "" {
			return strings.TrimSpace(strings.Split(m, ";")[0])
		}
	}
	buf := make([]byte, 512)
	n, _ := f.Read(buf)
	ct := http.DetectContentType(buf[:n])
	return strings.TrimSpace(strings.Split(ct, ";")[0])
}

// ToContract maps items to protocol artefacts.
func ToContract(items []Item) []contract.Artefact {
	out := make([]contract.Artefact, len(items))
	for i, it := range items {
		out[i] = contract.Artefact{
			Name:     it.Name,
			Size:     it.Size,
			Mime:     it.Mime,
			SHA256:   it.SHA256,
			Rejected: it.Rejected,
		}
	}
	return out
}
