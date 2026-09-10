// Package artefact lists and streams files from a run's /out directory.
// Results are untrusted: regular files only, Lstat + O_NOFOLLOW, MIME allow-list.
package artefact

import (
	"crypto/sha256"
	"encoding/hex"
	"io"
	"mime"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"syscall"

	"github.com/metadist/synaplan-compute/internal/names"
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

// List regular files under outDir. Symlinks are omitted. Total size above
// maxBytes marks subsequent files rejected.
func List(outDir string, maxBytes int64, mimeAllow []string) ([]Item, error) {
	entries, err := os.ReadDir(outDir)
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
		name := e.Name()
		if !names.FileName(name) {
			continue
		}
		full := filepath.Join(outDir, name)
		st, err := os.Lstat(full)
		if err != nil {
			continue
		}
		if !st.Mode().IsRegular() {
			continue
		}
		it := Item{Name: name, Size: st.Size(), Mime: sniff(full, name)}
		sum, err := hashFile(full)
		if err == nil {
			it.SHA256 = sum
		}
		if _, ok := allow[it.Mime]; !ok && len(allow) > 0 {
			it.Rejected = "mime_not_allowed"
		}
		if maxBytes > 0 && total+st.Size() > maxBytes {
			it.Rejected = "output_limit"
		}
		if it.Rejected == "" {
			total += st.Size()
		}
		items = append(items, it)
	}
	return items, nil
}

// Open streams a regular file; symlinks and odd names are refused.
func Open(outDir, name string) (*os.File, error) {
	if !names.FileName(name) {
		return nil, os.ErrInvalid
	}
	full := filepath.Join(outDir, name)
	st, err := os.Lstat(full)
	if err != nil {
		return nil, err
	}
	if !st.Mode().IsRegular() {
		return nil, os.ErrPermission
	}
	return os.OpenFile(full, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
}

func hashFile(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

func sniff(path, name string) string {
	if ext := strings.ToLower(filepath.Ext(name)); ext != "" {
		if m := mime.TypeByExtension(ext); m != "" {
			return strings.Split(m, ";")[0]
		}
	}
	f, err := os.Open(path)
	if err != nil {
		return "application/octet-stream"
	}
	defer f.Close()
	buf := make([]byte, 512)
	n, _ := f.Read(buf)
	ct := http.DetectContentType(buf[:n])
	return strings.Split(ct, ";")[0]
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
