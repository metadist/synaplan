package hostile

import (
	"bufio"
	"os"
	"path/filepath"
	"strings"
)

// Expectation is parsed from a corpus header.
type Expectation struct {
	File            string
	Result          string
	Reason          string
	TruncatedStdout bool
	Note            string
}

// ParseHeader reads expected-result fields from the leading comment block.
// Python scripts use "# key: value" lines, Node scripts "// key: value";
// parsing stops at the first line that is neither.
func ParseHeader(path string) (Expectation, error) {
	f, err := os.Open(path)
	if err != nil {
		return Expectation{}, err
	}
	defer f.Close()
	ex := Expectation{File: filepath.Base(path)}
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" {
			continue
		}
		body, ok := commentBody(line)
		if !ok {
			break
		}
		key, val, ok := strings.Cut(body, ":")
		if !ok {
			continue
		}
		key = strings.TrimSpace(key)
		val = strings.TrimSpace(val)
		switch key {
		case "expected-result":
			ex.Result = val
		case "reason":
			ex.Reason = val
		case "truncated-stdout":
			ex.TruncatedStdout = val == "true"
		case "note":
			ex.Note = val
		}
	}
	return ex, sc.Err()
}

func commentBody(line string) (string, bool) {
	switch {
	case strings.HasPrefix(line, "//"):
		return strings.TrimSpace(strings.TrimPrefix(line, "//")), true
	case strings.HasPrefix(line, "#"):
		return strings.TrimSpace(strings.TrimPrefix(line, "#")), true
	}
	return "", false
}
