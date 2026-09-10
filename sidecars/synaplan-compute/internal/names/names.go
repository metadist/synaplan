package names

import (
	"path"
	"regexp"
	"strings"
)

var fileNameRe = regexp.MustCompile(`^[A-Za-z0-9._-]{1,128}$`)

// FileName reports whether name is a safe input/artefact basename.
func FileName(name string) bool {
	if name == "" || strings.HasPrefix(name, "-") || strings.Contains(name, "..") {
		return false
	}
	if strings.ContainsAny(name, `/\`) {
		return false
	}
	return fileNameRe.MatchString(name)
}

// RelPath sanitizes a workspace-relative path. Empty path means the root.
func RelPath(p string) (string, bool) {
	p = strings.TrimSpace(p)
	if p == "" || p == "." {
		return "", true
	}
	p = strings.ReplaceAll(p, "\\", "/")
	if strings.HasPrefix(p, "/") || strings.Contains(p, ":") {
		return "", false
	}
	clean := path.Clean(p)
	if clean == ".." || strings.HasPrefix(clean, "../") {
		return "", false
	}
	for _, seg := range strings.Split(clean, "/") {
		if seg == "" || seg == "." || !fileNameRe.MatchString(seg) || strings.HasPrefix(seg, "-") {
			return "", false
		}
	}
	return clean, true
}
