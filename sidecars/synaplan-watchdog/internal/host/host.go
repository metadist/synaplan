// Package host reads where this server runs and what limits it has.
package host

import (
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

// Facts is one reading of this server's place and limits. No request data.
type Facts struct {
	Runtime       string
	Hostname      string
	Role          string
	Host          string
	Pod           string
	Namespace     string
	Deployment    string
	Container     string
	CPURequest    string
	MemoryRequest string
	LogCollector  string
	MemoryRSS     uint64
	MemoryLimit   uint64
	MemoryLimited bool
	CPUCores      float64
	CPULimited    bool
	CPUUsageUsec  uint64
	CPUUsageKnown bool
}

// A Deployment pod is <deployment>-<pod-template-hash>-<pod suffix>.
var deploymentPodSuffix = regexp.MustCompile(`-[a-z0-9]{5,10}-[a-z0-9]{5}$`)

// Read collects facts. root is empty on the real server and a temp directory
// in tests. env resolves environment variables.
func Read(root string, env func(string) string) Facts {
	if env == nil {
		env = os.Getenv
	}
	facts := Facts{
		Runtime:  runtimeOf(root, env),
		Hostname: hostname(env),
		Role:     firstNonEmpty(env("SYNAPLAN_ROLE"), "web"),
		Host:     env("SYNAPLAN_HOST_NAME"),
	}
	facts.LogCollector = collectorOf(facts.Runtime)
	readMemory(root, &facts)
	readCPU(root, &facts)
	if facts.Runtime == "kubernetes" {
		readKubernetes(root, env, &facts)
	}
	return facts
}

func readMemory(root string, facts *Facts) {
	if current, ok := readUint(path(root, "/sys/fs/cgroup/memory.current")); ok {
		facts.MemoryRSS = current
	} else if rss, ok := readProcRSS(path(root, "/proc/1/status")); ok {
		facts.MemoryRSS = rss
	}
	if limit, limited := readUint(path(root, "/sys/fs/cgroup/memory.max")); limited {
		facts.MemoryLimit = limit
		facts.MemoryLimited = true
	}
}

func readCPU(root string, facts *Facts) {
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
}

// readKubernetes takes the pod's place from the Downward API variables the
// chart sets (AU8) and from what the pod can see on its own.
func readKubernetes(root string, env func(string) string, facts *Facts) {
	facts.Namespace = firstNonEmpty(
		readTrim(path(root, "/var/run/secrets/kubernetes.io/serviceaccount/namespace")),
		env("SYNAPLAN_K8S_NAMESPACE"),
	)
	facts.Pod = firstNonEmpty(env("SYNAPLAN_SERVER_ID"), facts.Hostname)
	facts.Host = firstNonEmpty(env("SYNAPLAN_K8S_NODE"), facts.Host)
	facts.Container = env("SYNAPLAN_K8S_CONTAINER")
	facts.Deployment = firstNonEmpty(env("SYNAPLAN_K8S_DEPLOYMENT"), DeploymentOf(facts.Pod))
	facts.CPURequest = env("SYNAPLAN_CPU_REQUEST")
	facts.MemoryRequest = env("SYNAPLAN_MEMORY_LIMIT")
}

// DeploymentOf strips the ReplicaSet and pod suffixes from a Deployment pod
// name. A name without them is returned unchanged.
func DeploymentOf(pod string) string {
	return deploymentPodSuffix.ReplaceAllString(pod, "")
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

// collectorOf names what gathers this process's stderr: the container
// runtime, or the service manager on a bare host.
func collectorOf(runtime string) string {
	switch runtime {
	case "kubernetes":
		return "kubernetes"
	case "compose":
		return "docker"
	default:
		return "process"
	}
}

func hostname(env func(string) string) string {
	if name, err := os.Hostname(); err == nil && name != "" {
		return name
	}
	return env("HOSTNAME")
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

func readCPUMax(name string) (float64, bool) {
	fields := strings.Fields(readTrim(name))
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

func firstNonEmpty(values ...string) string {
	for _, value := range values {
		if value != "" {
			return value
		}
	}
	return ""
}
