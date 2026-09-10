# Threat model (A0 / CP3, updated with A2 controls)

This expands master-plan §4.3. Every row names a proving test or corpus script. A row without proof is not done.

The sentence this track exists to change (`PlatformCapabilityInventory` `KNOWN_ABSENT` id `code_execution`):

> The assistant cannot execute Python, shell, or other code on the server

The sandbox answers that by executing **only** inside an ephemeral T1 (or T2) container, with no Synaplan credentials inside the room, push-in / pull-out files, and an API that is not a shell.

Attacker positions: **script author** (model-written code), **prompt injector** (untrusted chat text that becomes a script), **compromised compute host**, **malicious artefact** (poisoned `/out` file).

| Threat | Attacker | Asset | T1 control | T2 control | Residual risk | Proof |
| ------ | -------- | ----- | ---------- | ---------- | ------------- | ----- |
| Container escape | script author | host kernel, other tenants | `Hardened`: NetworkMode none, ReadonlyRootfs, tmpfs noexec/nosuid, CapDrop ALL, no-new-privileges, uid 65534:65534, Init, never privileged, no host pid/ipc/uts, no devices, no docker.sock | gVisor `runsc` when `COMPUTE_TIER=gvisor`; Cloud requires T2 on a separate node | Kernel 0-days; accepted on T1, mitigated by T2/T3 | `TestHostConfigHardening`; `setuid.py` |
| Data exfiltration via network | script author / prompt injector | user files in `/work` | Empty `egress.allow` ⇒ NetworkMode none; `COMPUTE_EGRESS_ENABLED=false` refuses a non-empty list | Same API; runtime does not add a NIC | Operator enabling egress; PHP must pin IPs through `SsrfGuard` | `TestEgressEmptyMeansNoNetwork`; `TestEgressDisabledRefusesAllowList`; `dns_attempt.py` |
| Egress to an unpinned or private IP | prompt injector | internal network | Proxy dials only pinned IPs; missing `ips`, private ranges, too many hosts → `egress_not_allowed` | Same | Compromised PHP pinning a public IP the user should not reach (policy, not compute) | `TestProxyRefusesUnpinnedHost`; `TestProxyRefusesPrivateIp` |
| Fork bomb / PID exhaustion | script author | host scheduler | `PidsLimit`; Init reaps the tree | gVisor extra | Shared-host noisy neighbor on T1 | `fork_bomb.py`; `TestHostConfigHardening` PidsLimit |
| Disk fill | script author | host disk | `/work` `/out` bind mounts, tmpfs size, `fsize` ulimit, `outputMb` | Same | Scratch volume on the compute node must be sized (`docs` sizing in A3) | `disk_fill.py`; `TestArtefactSizeCap`; `TestWorkspaceQuotaKillsRun` |
| Infinite loop / hang | script author | capacity slots | `context.WithTimeout` then SIGKILL + `ContainerRemove{Force}` | Same | Slow disk on kill | `long_sleep.py` |
| `/proc` walk / host environ | script author | host secrets | Namespaced proc; non-root; no proc mount from the host | gVisor | Timing side channels (below) | `proc_walk.py` |
| Symlink artefact to host files | malicious artefact / script author | `/etc/passwd`, sibling scratch | List uses `Lstat`; download `O_NOFOLLOW`; names sanitized | Same | None material on T1 | `symlink_out.py`; `TestArtefactSymlinkOmitted` |
| Poisoned MIME / huge artefact | malicious artefact | PHP ingest, browser preview | MIME allow-list; size cap; provenance `source: compute` (Phase B); compute never asks PHP to exec | Same | User opens a malicious PDF in a local viewer | `TestArtefactMimeRejected`; `TestArtefactSizeCap` |
| Log injection through stdout | script author | operator logs, PHP UI | Server-side truncation; UTF-8 sanitize; audit log never contains stdout | Same | UI must still escape (Phase B) | `huge_stdout.py`; `TestLogTruncation`; `TestAuditNeverLogsOutput` |
| Cross-user workspace mount | prompt injector / buggy PHP | other user's files | Opaque ULID ids; owner match; path never in API; mount only on owner match | Same | Compromised PHP sending another user's id — compute still 403s if owner mismatches | `TestRunWithForeignWorkspaceRefused`; `TestWorkspaceListNeverReturnsPaths` |
| Run without owner / free image / free program | buggy PHP | host images, supply chain | Owner required; image is a map key; program allow-list (`python`/`sh`, `node`/`sh`) | Same | None | `TestRunRejectsMissingOwner`; `TestRunRejectsUnknownImage`; `TestRunRejectsProgramNotInAllowList` |
| Limits above instance caps | buggy PHP / DoS | host memory | Caps from health; `limits_exceed_caps` | Same | None | `TestRunRejectsLimitsAboveCaps` |
| Compute API denial of service | unauthenticated caller | capacity | Bearer `COMPUTE_AUTH_TOKEN` ≥ 32 bytes, constant-time compare; health unauthenticated; `COMPUTE_MAX_CONCURRENT` + queue; `429 capacity_exceeded` | Same | Stolen token = full compute API (token grants nothing on Synaplan) | `TestUnauthenticatedRunsRejected`; `TestHealthIsPublic`; `TestNewRejectsShortToken` |
| Silent T1 where T2 was configured | misconfig | Cloud posture (row 14) | `COMPUTE_TIER=gvisor` fails startup without `runsc` | Forced gVisor | Operator not setting the env | `TestForcedTierFailsStartupWithoutRuntime` |
| Timing side channels between concurrent runs | script author | co-tenant secrets | Accepted on T1 (shared kernel) | Mitigated by T2/T3 and a separate compute node for Cloud | Cache/timing leakage on T1 self-host | Documented residual; Cloud requires T2 |
| Image supply chain | compromised registry | every run | Digest-pinned map; a run never pulls; `RequireDigests` | Cosign in A3 | Placeholder digests until A3 publishes | `TestImageMapRequiresDigest` |
| Compute service compromise | compromised compute host | Synaplan credentials, user PII | Service holds only the bearer token; no user emails, no file paths, no Synaplan DB; not published to the internet | Separate node | Attacker can run more sandboxes as that token | Audit field list; `TestAuditNeverLogsOutput` |
| Prompt-injected code | prompt injector | user files selected for the run | Policy (track 4) decides; sandbox limits blast radius; audit `run.accepted` / `run.finished` with image, limits, reason — no stdout | Same | User-selected files are in `/work` by design | `TestAuditRefusedRunHasReason`; policy in Phase B |
| API shell string | prompt injector | host via compute process | No free-form shell in the contract; `entry.program` + `args[]`; `scripts/no-shell-guard.sh` | Same | `sh` as an in-sandbox program is allowed (decision 3) | `scripts/no-shell-guard.sh`; `TestUnknownFieldRejected` |

PHP/SsrfGuard remains the resolver of egress hosts. Compute trusts only `{host, port, ips[]}` and never performs DNS for allow-list entries.
