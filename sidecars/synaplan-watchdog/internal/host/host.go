// Package host reads the limits of this server from the cgroup and /proc.
package host

import (
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

// Facts is one reading of this server's limits. No request data.
type Facts struct {
	Runtime       string
	MemoryRSS     uint64
	MemoryLimit   uint64
	MemoryLimited bool
	CPUCores      float64
	CPULimited    bool
	CPUUsageUsec  uint64
	CPUUsageKnown bool
	Namespace     string
	Node          string
	Pod           string
	CPURequest    string
	MemoryRequest string
}

// Read collects facts. root is empty on the real server and a temp directory
// in tests. env resolves environment variables.
func Read(root string, env func(string) string) Facts {
	if env == nil {
		env = os.Getenv
	}
	facts := Facts{Runtime: runtimeOf(root, env)}
	if current, ok := readUint(path(root, "/sys/fs/cgroup/memory.current")); ok {
		facts.MemoryRSS = current
	} else if rss, ok := readProcRSS(path(root, "/proc/1/status")); ok {
		facts.MemoryRSS = rss
	}
	if limit, limited := readMemoryMax(path(root, "/sys/fs/cgroup/memory.max")); limited {
		facts.MemoryLimit = limit
		facts.MemoryLimited = true
	}
	if cores, limited := readCPUMax(path(root, "/sys/fs/cgroup/cpu.max")); limited {
		facts.CPUCores = cores
		facts.CPULimited = true
	} else if online := readCPUOnline(path(root, "/sys/devices/system/cpu/online")); online > 0 {
		facts.CPUCores = online
	}
	if usage, ok := readCPUUsage(path(root, "/sys/fs/cgroup/cpu.stat")); ok {
		facts.CPUUsageUsec = usage
		facts.CPUUsageKnown = true
	}
	if facts.Runtime == "kubernetes" {
		facts.Namespace = readTrim(path(root, "/var/run/secrets/kubernetes.io/serviceaccount/namespace"))
		if facts.Namespace == "" {
			facts.Namespace = env("SYNAPLAN_K8S_NAMESPACE")
		}
		facts.Node = env("SYNAPLAN_K8S_NODE")
		facts.Pod = env("SYNAPLAN_SERVER_ID")
		if facts.Pod == "" {
			facts.Pod = env("HOSTNAME")
		}
		facts.CPURequest = env("SYNAPLAN_CPU_REQUEST")
		facts.MemoryRequest = env("SYNAPLAN_MEMORY_LIMIT")
	}
	return facts
}

func runtimeOf(root string, env func(string) string) string {
	if env("KUBERNETES_SERVICE_HOST") != "" {
		return "kubernetes"
	}
	if _, err := os.Stat(path(root, "/.dockerenv")); err == nil {
		return "compose"
	}
	return "host"
}

func path(root, name string) string {
	if root == "" {
		return name
	}
	return filepath.Join(root, strings.TrimPrefix(name, "/"))
}

func readTrim(name string) string {
	body, err := os.ReadFile(name)
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(body))
}

func readUint(name string) (uint64, bool) {
	text := readTrim(name)
	if text == "" || text == "max" {
		return 0, false
	}
	value, err := strconv.ParseUint(text, 10, 64)
	if err != nil {
		return 0, false
	}
	return value, true
}

func readMemoryMax(name string) (uint64, bool) {
	text := readTrim(name)
	if text == "" || text == "max" {
		return 0, false
	}
	return readUint(name)
}

func readCPUMax(name string) (float64, bool) {
	text := readTrim(name)
	if text == "" {
		return 0, false
	}
	fields := strings.Fields(text)
	if len(fields) != 2 || fields[0] == "max" {
		return 0, false
	}
	quota, err1 := strconv.ParseFloat(fields[0], 64)
	period, err2 := strconv.ParseFloat(fields[1], 64)
	if err1 != nil || err2 != nil || period == 0 {
		return 0, false
	}
	return quota / period, true
}

func readCPUUsage(name string) (uint64, bool) {
	body, err := os.ReadFile(name)
	if err != nil {
		return 0, false
	}
	for _, line := range strings.Split(string(body), "\n") {
		fields := strings.Fields(line)
		if len(fields) == 2 && fields[0] == "usage_usec" {
			value, err := strconv.ParseUint(fields[1], 10, 64)
			if err != nil {
				return 0, false
			}
			return value, true
		}
	}
	return 0, false
}

func readCPUOnline(name string) float64 {
	text := readTrim(name)
	if text == "" {
		return 0
	}
	var count float64
	for _, part := range strings.Split(text, ",") {
		part = strings.TrimSpace(part)
		if part == "" {
			continue
		}
		bounds := strings.Split(part, "-")
		if len(bounds) == 1 {
			count++
			continue
		}
		start, err1 := strconv.Atoi(bounds[0])
		end, err2 := strconv.Atoi(bounds[1])
		if err1 != nil || err2 != nil || end < start {
			continue
		}
		count += float64(end - start + 1)
	}
	return count
}

func readProcRSS(name string) (uint64, bool) {
	body, err := os.ReadFile(name)
	if err != nil {
		return 0, false
	}
	for _, line := range strings.Split(string(body), "\n") {
		if !strings.HasPrefix(line, "VmRSS:") {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) < 2 {
			return 0, false
		}
		kb, err := strconv.ParseUint(fields[1], 10, 64)
		if err != nil {
			return 0, false
		}
		return kb * 1024, true
	}
	return 0, false
}
