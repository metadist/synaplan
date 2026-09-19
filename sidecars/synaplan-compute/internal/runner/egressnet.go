package runner

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"strings"
	"time"

	"github.com/docker/docker/api/types"
	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/api/types/filters"
	"github.com/docker/docker/api/types/network"
	"github.com/metadist/synaplan-compute/pkg/contract"
)

const (
	// egressNetworkPrefix names per-run internal networks. ValidateHardened
	// only accepts NetworkMode none or this prefix (plus proxy env + dead
	// DNS), so a run can never land on bridge, host, or a foreign network.
	egressNetworkPrefix = "compute-egress-"
	// egressOutboundNetwork is the single long-lived bridge that gives
	// proxies (and only proxies) a route out. Run containers never join
	// it, so cross-run proxy use is topologically impossible; that is why
	// the proxy needs no auth token (urllib couldn't present one anyway).
	egressOutboundNetwork = "compute-egress-out"
	// egressProxyPort is the CONNECT listener inside the proxy container.
	egressProxyPort = 3128
	// proxyLabel marks proxy containers and per-run networks for the orphan
	// sweep. The outbound network carries outboundLabel instead so a sweep
	// never deletes the shared route.
	proxyLabelKey      = "synaplan.compute.proxy"
	proxyLabelValue    = "1"
	outboundLabelKey   = "synaplan.compute.egress-out"
	outboundLabelValue = "1"
	// The proxy container runs ["proxy"] — the image ENTRYPOINT is already
	// /synaplan-compute (Dockerfile). Keep in sync if that ever changes.
	proxyCmd = "proxy"
	// proxyReadyTimeout bounds waiting for the proxy's listen line.
	proxyReadyTimeout = 10 * time.Second
)

// EgressNet is one run's private network: exactly two members (proxy +
// run container), no external route, dead DNS. The run container reaches
// the outside world only through ProxyURL, which enforces the pin map.
type EgressNet struct {
	NetworkID string
	Network   string
	ProxyID   string
	ProxyURL  string
}

// EgressConfig tunes proxy creation.
type EgressConfig struct {
	// Image overrides the proxy image. Empty resolves the sidecar's own
	// image (fail closed when that fails).
	Image string
}

// EgressNetworkName derives the per-run network name. Run IDs are ULIDs;
// lowercase keeps the name within Docker's charset.
func EgressNetworkName(runID string) string {
	return egressNetworkPrefix + strings.ToLower(runID)
}

// ProxyEnv renders the only three variables an egress run container gets.
// The proxy address is an IP literal so no DNS lookup is needed (DNS is
// dead inside egress runs on purpose).
func ProxyEnv(proxyURL string) []string {
	return []string{
		"HTTP_PROXY=" + proxyURL,
		"HTTPS_PROXY=" + proxyURL,
		"NO_PROXY=localhost,127.0.0.1",
	}
}

// SetupEgress creates the run's private network plus its throwaway proxy
// carrying allow, waits until the proxy listens, and returns the attachment
// for Spec. Any failure tears down what was created and fails the run —
// a run never starts half-networked.
func (d *Docker) SetupEgress(ctx context.Context, cfg EgressConfig, runID string, allow []contract.EgressHost) (*EgressNet, error) {
	if !d.Available() {
		return nil, ErrUnavailable
	}
	image, err := d.resolveProxyImage(ctx, cfg.Image)
	if err != nil {
		return nil, err
	}
	networkName := EgressNetworkName(runID)
	netResp, err := d.cli.NetworkCreate(ctx, networkName, network.CreateOptions{
		Internal: true,
		Labels:   map[string]string{runLabelKey: runLabelValue, proxyLabelKey: proxyLabelValue},
	})
	if err != nil {
		return nil, fmt.Errorf("create egress network: %w", err)
	}
	net := &EgressNet{NetworkID: netResp.ID, Network: networkName}
	cleanup := true
	defer func() {
		if cleanup {
			_ = d.TeardownEgress(context.Background(), net)
		}
	}()
	allowJSON, err := json.Marshal(allow)
	if err != nil {
		return nil, fmt.Errorf("encode egress policy: %w", err)
	}
	outboundID, err := d.ensureOutboundNetwork(ctx)
	if err != nil {
		return nil, err
	}
	proxyID, proxyIP, err := d.startProxy(ctx, networkName, netResp.ID, outboundID, image, string(allowJSON))
	if err != nil {
		return nil, err
	}
	net.ProxyID = proxyID
	net.ProxyURL = fmt.Sprintf("http://%s:%d", proxyIP, egressProxyPort)
	cleanup = false
	return net, nil
}

// TeardownEgress removes the proxy container and the run network.
// Best-effort: leftovers carry the run label, so SweepOrphans reaps them.
func (d *Docker) TeardownEgress(ctx context.Context, net *EgressNet) error {
	if net == nil {
		return nil
	}
	var firstErr error
	if d.Available() {
		if net.ProxyID != "" {
			if err := d.cli.ContainerRemove(ctx, net.ProxyID, container.RemoveOptions{Force: true}); err != nil && firstErr == nil {
				firstErr = err
			}
		}
		if net.NetworkID != "" {
			if err := d.cli.NetworkRemove(ctx, net.NetworkID); err != nil && firstErr == nil {
				firstErr = err
			}
		}
	}
	return firstErr
}

// SweepEgressNetworks removes stale per-run networks (crash leftovers).
func (d *Docker) SweepEgressNetworks(ctx context.Context) error {
	if !d.Available() {
		return nil
	}
	args := filters.NewArgs(filters.Arg("label", proxyLabelKey+"="+proxyLabelValue))
	nets, err := d.cli.NetworkList(ctx, network.ListOptions{Filters: args})
	if err != nil {
		return err
	}
	var firstErr error
	for _, n := range nets {
		if err := d.cli.NetworkRemove(ctx, n.ID); err != nil && firstErr == nil {
			firstErr = err
		}
	}
	return firstErr
}

// resolveProxyImage prefers the configured image, else the image this
// sidecar runs from (fail closed when that fails).
func (d *Docker) resolveProxyImage(ctx context.Context, configured string) (string, error) {
	if strings.TrimSpace(configured) != "" {
		return strings.TrimSpace(configured), nil
	}
	self, err := d.inspectSelf(ctx)
	if err != nil {
		return "", err
	}
	if self.Image == "" {
		return "", fmt.Errorf("proxy image: own container has no image")
	}
	return self.Image, nil
}

// inspectSelf inspects our own container (hostname == container ID, Docker
// default) for mounts and image resolution.
func (d *Docker) inspectSelf(ctx context.Context) (types.ContainerJSON, error) {
	var empty types.ContainerJSON
	id, err := os.Hostname()
	if err != nil || strings.TrimSpace(id) == "" {
		return empty, fmt.Errorf("cannot determine own container: %w", err)
	}
	info, err := d.cli.ContainerInspect(ctx, strings.TrimSpace(id))
	if err != nil {
		return empty, fmt.Errorf("cannot inspect own container: %w", err)
	}
	return info, nil
}

// startProxy runs the policy-carrying proxy on netName and waits for its
// listen line. Returns the container ID and its IP on that network.
func (d *Docker) startProxy(ctx context.Context, networkName, networkID, outboundID, image, allowJSON string) (string, string, error) {
	mem := int64(64 * 1024 * 1024)
	pids := int64(32)
	initTrue := true
	resp, err := d.cli.ContainerCreate(ctx,
		&container.Config{
			Image: image,
			Cmd:   []string{proxyCmd},
			Env: []string{
				"EGRESS_ALLOW_JSON=" + allowJSON,
				fmt.Sprintf("EGRESS_PORT=%d", egressProxyPort),
			},
			Labels: map[string]string{runLabelKey: runLabelValue, proxyLabelKey: proxyLabelValue},
		},
		&container.HostConfig{
			NetworkMode:    container.NetworkMode(networkName),
			ReadonlyRootfs: true,
			Tmpfs:          map[string]string{"/tmp": "rw,noexec,nosuid,nodev,size=16m"},
			CapDrop:        []string{"ALL"},
			SecurityOpt:    []string{"no-new-privileges"},
			Init:           &initTrue,
			Resources: container.Resources{
				Memory:     mem,
				MemorySwap: mem,
				PidsLimit:  &pids,
			},
		},
		&network.NetworkingConfig{
			EndpointsConfig: map[string]*network.EndpointSettings{
				networkID:  {},
				outboundID: {},
			},
		},
		nil,
		"",
	)
	if err != nil {
		return "", "", fmt.Errorf("create proxy container: %w", err)
	}
	proxyID := resp.ID
	started := false
	defer func() {
		if !started {
			cctx, cancel := cleanupContext()
			defer cancel()
			_ = d.cli.ContainerRemove(cctx, proxyID, container.RemoveOptions{Force: true})
		}
	}()
	if err := d.cli.ContainerStart(ctx, proxyID, container.StartOptions{}); err != nil {
		return "", "", fmt.Errorf("start proxy container: %w", err)
	}
	ip, err := d.proxyIP(ctx, proxyID, networkID, networkName)
	if err != nil {
		return "", "", err
	}
	if err := d.waitProxyReady(ctx, proxyID); err != nil {
		return "", "", err
	}
	started = true
	return proxyID, ip, nil
}

// proxyIP reads the proxy's address on the run network. Only that network's
// IP is ever handed to the run container.
func (d *Docker) proxyIP(ctx context.Context, proxyID, networkID, networkName string) (string, error) {
	info, err := d.cli.ContainerInspect(ctx, proxyID)
	if err != nil {
		return "", fmt.Errorf("inspect proxy: %w", err)
	}
	if info.NetworkSettings == nil {
		return "", fmt.Errorf("proxy has no network settings")
	}
	for _, key := range []string{networkName, networkID} {
		if ep := info.NetworkSettings.Networks[key]; ep != nil && ep.IPAddress != "" {
			return ep.IPAddress, nil
		}
	}
	return "", fmt.Errorf("proxy has no IP on the run network")
}

// ensureOutboundNetwork returns the shared proxy-egress network, creating
// it when missing. Refuses to adopt a same-named foreign network.
func (d *Docker) ensureOutboundNetwork(ctx context.Context) (string, error) {
	existing, err := d.cli.NetworkInspect(ctx, egressOutboundNetwork, network.InspectOptions{})
	if err == nil {
		if existing.Labels[outboundLabelKey] != outboundLabelValue || existing.Internal {
			return "", fmt.Errorf("network %q exists but is not ours", egressOutboundNetwork)
		}
		return existing.ID, nil
	}
	resp, err := d.cli.NetworkCreate(ctx, egressOutboundNetwork, network.CreateOptions{
		Labels: map[string]string{outboundLabelKey: outboundLabelValue},
	})
	if err != nil {
		// Lost a create race with a concurrent first run: re-inspect once.
		if existing, rerr := d.cli.NetworkInspect(ctx, egressOutboundNetwork, network.InspectOptions{}); rerr == nil {
			if existing.Labels[outboundLabelKey] != outboundLabelValue || existing.Internal {
				return "", fmt.Errorf("network %q exists but is not ours", egressOutboundNetwork)
			}
			return existing.ID, nil
		}
		return "", fmt.Errorf("create outbound network: %w", err)
	}
	return resp.ID, nil
}

// waitProxyReady polls the proxy log for its listen line: scripts must
// never see connection refused on their first fetch.
func (d *Docker) waitProxyReady(ctx context.Context, proxyID string) error {
	deadline := time.Now().Add(proxyReadyTimeout)
	for {
		rc, err := d.cli.ContainerLogs(ctx, proxyID, container.LogsOptions{
			ShowStdout: true, ShowStderr: true, Tail: "20",
		})
		if err == nil {
			body, _ := io.ReadAll(io.LimitReader(rc, 64*1024))
			_ = rc.Close()
			if strings.Contains(string(body), "proxy: listening") {
				return nil
			}
			info, ierr := d.cli.ContainerInspect(ctx, proxyID)
			if ierr == nil && info.State != nil && !info.State.Running {
				return fmt.Errorf("proxy exited before listening")
			}
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("proxy not listening after %s", proxyReadyTimeout)
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(100 * time.Millisecond):
		}
	}
}
