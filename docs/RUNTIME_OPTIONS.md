> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 1: Getting Started & Deployment (5/8)**  
> **← Previous:** [1.4 Feature Flags & Module Gates](FEATURE_FLAGS.md) · **Next:** [1.6 Updating a Self-Hosted Instance →](UPDATE_SELFHOST.md)

# Runtime and Outbound Options

> **In this guide:** Operator reference for the FrankenPHP worker pools (`api` and `streams`), the capacity watchdog, usage recording, and outbound HTTP switches (`EGRESS.MODE`). For step-by-step operator instructions, see [Runtime and outbound traffic](https://docs.synaplan.com/runtime-setup).

---

## Runtime & Outbound Settings Reference

| Setting | Default | Where It Is Configured |
| ------- | ------- | ---------------------- |
| `FRANKENPHP_WORKER_NUM` | `16` | Environment. Number of PHP workers in the `api` pool (short requests). Production worker mode only. |
| `FRANKENPHP_STREAM_WORKER_NUM` | `FRANKENPHP_WORKER_NUM` | Environment. Number of PHP workers in the `streams` pool (long SSE/streaming AI routes). |
| `FRANKENPHP_MAX_WAIT_TIME` | `15s` | Environment. Maximum queue wait before returning HTTP `503` (`server_busy`, `Retry-After: 5`). |
| `SYNAPLAN_WATCHDOG` | `1` | Environment. Set `0` to disable the watchdog and hide **Admin → Capacity**. |
| `SYNAPLAN_SERVER_ID`, `SYNAPLAN_HOST_NAME`, `SYNAPLAN_NODE_ADDRESS` | *unset* | Environment. Per-node identity in a multi-node cluster. Never share one ID or address across machines. |
| `AI.USAGE_HOOK` | `legacy` | `BCONFIG` group `AI`, setting `USAGE_HOOK`, owner `0`. Values: `legacy`, `shadow`, or `on`. No admin UI field. |
| `EGRESS.MODE` | `off` | **Admin → System configuration → Outbound traffic** (`BCONFIG`). Values: `off`, `report`, or `enforce`. |
| `EGRESS.ALLOW` | *empty* | Same tab. Additional allowed outbound hostnames (for example custom OpenAI-compatible gateways). |
| `EGRESS_PROXY` | *empty* | Environment. Outbound HTTP/HTTPS proxy URL; overrides `HTTPS_PROXY` when set. |
| `EGRESS_NO_PROXY` | `NO_PROXY` | Environment. Hosts that bypass the proxy and are not refused in `enforce` mode. |

---

## Related Guides

- **Feature & tool switches:** `TOOLS.REGISTRY_ENABLED` and wave feature flags are listed in [Feature Flags & Module Gates](FEATURE_FLAGS.md).
- **Adding a provider to the allowlist:** A provider is one class whose endpoints automatically join the outbound allowlist—see [Adding an AI Provider](PROVIDERS.md).
- **Architecture & egress internals:** See [Backend Architecture](ARCHITECTURE.md) (worker pools, provider catalog, tool path) and [Outbound Traffic & Egress Control](EGRESS.md) (what is wrapped and how the optional egress gateway works).

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 1: Getting Started & Deployment (5/8)**  
> **← Previous:** [1.4 Feature Flags & Module Gates](FEATURE_FLAGS.md) · **Next:** [1.6 Updating a Self-Hosted Instance →](UPDATE_SELFHOST.md)
