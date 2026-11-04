# Runtime and outbound options

Operator switches for the worker pools, the capacity page, usage recording
and outbound HTTP. The guide, with each value and where to change it, is
[Runtime and outbound traffic](https://docs.synaplan.com/runtime-setup).

| Setting | Default | Where |
| ------- | ------- | ----- |
| `FRANKENPHP_WORKER_NUM` | `16` | Environment. Workers in the `api` pool. Production worker mode only. |
| `FRANKENPHP_STREAM_WORKER_NUM` | the api pool's count | Environment. Workers in the `streams` pool. |
| `FRANKENPHP_MAX_WAIT_TIME` | `15s` | Environment. Then HTTP 503, `server_busy`, `Retry-After: 5`. |
| `SYNAPLAN_WATCHDOG` | `1` | Environment. `0` hides **Operate → Capacity**. |
| `SYNAPLAN_SERVER_ID`, `SYNAPLAN_HOST_NAME`, `SYNAPLAN_NODE_ADDRESS` | unset | Environment. Do not share one id or address across machines. |
| `AI.USAGE_HOOK` | `legacy` | `BCONFIG` group `AI`, setting `USAGE_HOOK`, owner `0`. `legacy`, `shadow` or `on`. No admin field. |
| `EGRESS.MODE` | `off` | **Operate → System configuration → Outbound traffic**. `off`, `report` or `enforce`. |
| `EGRESS.ALLOW` | empty | Same tab. Extra hosts. |
| `EGRESS_PROXY` | empty | Environment. Overrides `HTTPS_PROXY` when set. |
| `EGRESS_NO_PROXY` | falls back to `NO_PROXY` | Environment. `enforce` does not refuse these hosts. |

Tool flags (`TOOLS.REGISTRY_ENABLED` and the rest) are in
[FEATURE_FLAGS.md](FEATURE_FLAGS.md). A provider is one class:
[PROVIDERS.md](PROVIDERS.md).

Developer maps, not operator steps:
[ARCHITECTURE.md](ARCHITECTURE.md) (pools, the provider catalog, the tool
path), [EGRESS.md](EGRESS.md) (what is wrapped and what is not).
