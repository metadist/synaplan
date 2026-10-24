package host

import (
	"os"
	"path/filepath"
	"testing"
)

func TestReadComposeCgroup(t *testing.T) {
	root := t.TempDir()
	writeFile(t, root, ".dockerenv", "")
	writeFile(t, root, "sys/fs/cgroup/memory.current", "2202009600\n")
	writeFile(t, root, "sys/fs/cgroup/memory.max", "6442450944\n")
	writeFile(t, root, "sys/fs/cgroup/cpu.max", "800000 100000\n")
	writeFile(t, root, "sys/fs/cgroup/cpu.stat", "usage_usec 5000000\nnr_periods 1\n")

	env := map[string]string{"SYNAPLAN_HOST_NAME": "synweb100", "SYNAPLAN_ROLE": "web"}
	facts := Read(root, func(key string) string { return env[key] })
	if facts.Runtime != "compose" || facts.LogCollector != "docker" {
		t.Fatalf("runtime = %s collector = %s", facts.Runtime, facts.LogCollector)
	}
	if facts.Host != "synweb100" || facts.Role != "web" || facts.Hostname == "" {
		t.Fatalf("place = %+v", facts)
	}
	if facts.Pod != "" || facts.Deployment != "" {
		t.Fatalf("compose has no pod: %+v", facts)
	}
	if facts.MemoryRSS != 2202009600 || !facts.MemoryLimited || facts.MemoryLimit != 6442450944 {
		t.Fatalf("memory = %+v", facts)
	}
	if !facts.CPULimited || facts.CPUCores != 8 {
		t.Fatalf("cpu cores = %v limited %v", facts.CPUCores, facts.CPULimited)
	}
	if !facts.CPUUsageKnown || facts.CPUUsageUsec != 5000000 {
		t.Fatalf("cpu usage = %d known %v", facts.CPUUsageUsec, facts.CPUUsageKnown)
	}
}

func TestReadKubernetesAndProcRSS(t *testing.T) {
	root := t.TempDir()
	writeFile(t, root, "proc/1/status", "Name:\tfrankenphp\nVmRSS:\t2048 kB\n")
	writeFile(t, root, "sys/fs/cgroup/memory.max", "max\n")
	writeFile(t, root, "sys/fs/cgroup/cpu.max", "max 100000\n")
	writeFile(t, root, "sys/devices/system/cpu/online", "0-3\n")
	writeFile(t, root, "var/run/secrets/kubernetes.io/serviceaccount/namespace", "synaplan\n")

	env := map[string]string{
		"KUBERNETES_SERVICE_HOST": "10.0.0.1",
		"SYNAPLAN_SERVER_ID":      "synaplan-web-7d9f8b6c4-x2k9q",
		"SYNAPLAN_K8S_NODE":       "node-a",
		"SYNAPLAN_K8S_CONTAINER":  "synaplan",
		"SYNAPLAN_CPU_REQUEST":    "1",
		"SYNAPLAN_MEMORY_LIMIT":   "3Gi",
	}
	facts := Read(root, func(key string) string { return env[key] })
	if facts.Runtime != "kubernetes" || facts.LogCollector != "kubernetes" {
		t.Fatalf("runtime = %s collector = %s", facts.Runtime, facts.LogCollector)
	}
	if facts.MemoryRSS != 2048*1024 || facts.MemoryLimited {
		t.Fatalf("memory = %+v", facts)
	}
	if facts.CPULimited || facts.CPUCores != 4 {
		t.Fatalf("cpu = %v limited %v", facts.CPUCores, facts.CPULimited)
	}
	if facts.Namespace != "synaplan" || facts.Pod != "synaplan-web-7d9f8b6c4-x2k9q" || facts.Host != "node-a" {
		t.Fatalf("k8s = %+v", facts)
	}
	if facts.Deployment != "synaplan-web" || facts.Container != "synaplan" {
		t.Fatalf("deployment = %q container = %q", facts.Deployment, facts.Container)
	}
	if facts.CPURequest != "1" || facts.MemoryRequest != "3Gi" {
		t.Fatalf("requests = %s %s", facts.CPURequest, facts.MemoryRequest)
	}
}

func TestDeploymentOf(t *testing.T) {
	cases := map[string]string{
		"synaplan-web-7d9f8b6c4-x2k9q": "synaplan-web",
		"synaplan-5bd9c-abcde":         "synaplan",
		"synaplan-web":                 "synaplan-web",
		"web1":                         "web1",
	}
	for pod, want := range cases {
		if got := DeploymentOf(pod); got != want {
			t.Errorf("DeploymentOf(%q) = %q, want %q", pod, got, want)
		}
	}
}

func TestReadHostWhenNothingIsMounted(t *testing.T) {
	facts := Read(t.TempDir(), func(string) string { return "" })
	if facts.Runtime != "host" || facts.LogCollector != "process" || facts.Role != "web" {
		t.Fatalf("facts = %+v", facts)
	}
}

func writeFile(t *testing.T, root, name, body string) {
	t.Helper()
	full := filepath.Join(root, name)
	if err := os.MkdirAll(filepath.Dir(full), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(full, []byte(body), 0o644); err != nil {
		t.Fatal(err)
	}
}
