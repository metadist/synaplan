# Security and compatibility

Binding for every sprint. The July 2026 local-agent research is the
threat-model source; this file is the checklist used in PR review.

---

## 1. Threat directions

The dangerous direction is **not** “a bad laptop attacks Synaplan”
(scopes + rate limits handle that). It is:

> The server, or a prompt-injected model, must not make the laptop do
> arbitrary things.

Job payloads and tool_use blocks are model-influenced. Community skill
scripts are attacker-controlled once the user installed them. Design for
both.

---

## 2. API keys

| Rule | Detail |
| ---- | ------ |
| Grandfather | Empty scopes and legacy `webhooks:*` lists remain full access |
| Desktop keys | Pairing mints only `desktop:messages`, `desktop:mcp`, `desktop:files`, `desktop:jobs` |
| Enforcement | Central listener (Sprint A1). Desktop key cannot hit `/api/v1/admin/*`, user admin, webhooks |
| Revoke | Deleting a device revokes the key. 401 on next call |
| Storage | OS keychain on the client. Never log the secret. Shown once at pair |
| Scopes unused today | `hasScope()` is dead code until Sprint A1 — **that sprint is a security fix**, not a feature |

Stolen laptop: user revokes the device on the web. That is the recovery
story. Do not ship pairing before Sprint A1 — and note that the server-first
order makes this automatic: scopes are the first steps of the epic, and no
key can reach a laptop for weeks afterwards because no laptop client exists.

---

## 3. Filesystem allowlist (client authority)

1. Config file / UI on the machine is the source of truth.
2. Server cannot add roots.
3. `realpath` / canonicalize **then** contain.
4. Deny globs always apply (`.ssh`, `.env`, keys, `.git/config`).
5. Skill dir is readable; writes go to `~/Synaplan/out` (or user write
   roots) only.
6. Zip/git install: reject `..`, symlinks, bare SKILL.md at zip root.

Tests for symlink escape and zip slip are mandatory (Sprints B2–B3).

---

## 4. What may run locally

| Source | Allowed? |
| ------ | -------- |
| User-typed chat + installed skill + model-emitted `Bash` | Yes, after Sprint B2 policy / confirm |
| `skill.run` job with `{skill, prompt}` for an enabled skill | Yes, Sprint B5, unattended opt-in per skill |
| Server field `command` / `script` / `argv` | **Never.** Ignore extra keys |
| Job type other than `skill.run` | Refuse |
| Skill name not installed / disabled | Refuse, report `unknown_skill` |
| Community skill `install.sh` | Never auto-run |
| Subprocess environment | No `sk_`, no pairing code |

Sprint B4 binary allowlist: `python3`, `node`, `soffice`, skill scripts.
Default deny everything else (including `curl`, PowerShell, `cmd`).

**Server-first consequence:** the “never execute a server-supplied command”
rule is written into the frozen contract in Sprint A3 (`DS18`) and asserted by
the harness before a device exists, so the client inherits it as a
specification rather than discovering it during review.

---

## 5. Skills as supply chain

- Installing = executing later. Confirm with license + file list.
- Pin git installs to a SHA.
- No auto-update from the network.
- Bundled skills: Apache-2.0 (or compatible), reviewed, SHA in
  `docs/BUNDLED_SKILLS.md`.
- Agent37 / random GitHub: user-initiated, no Synaplan blessing in copy.
- Results re-ingested into RAG: size cap, MIME allowlist, provenance.

---

## 6. Network

- Desktop → Synaplan HTTPS only (http = loopback for dev).
- Pin pairing host; no redirect to another host.
- Skill scripts: network **off** unless a later skill declares it and
  the user opts in (pptx does not need it).
- Do not register the laptop as an inbound MCP server (`SsrfGuard`
  would block it; tunnels are out of v1).

---

## 7. Product compatibility (do not break Synaplan)

| Surface | Rule |
| ------- | ---- |
| Widget | No Desktop code paths |
| Mobile | `backend-only` / `ota-candidate` classification; no store-required |
| `/v1` `/mcp` | Additive tools and headers only |
| Routing snapshots | Empty diff |
| M365 / Synamail | Still the Outlook product; desktop does not steal OAuth |
| Messages gateway | Required, not replaced |
| Plugin prompt-packs | Separate epic; no shared installer |

---

## 8. Privacy and logging

- Do not log file contents, prompts, or pairing codes at info.
- Local audit (July §2.7): device-side log of paths touched and
  commands run (hashes / argv, not file bodies). Survives in
  `~/.synaplan-desktop/audit.log` with rotation. Server-side: job
  id, device, skill name, status — not the deck contents.
- GDPR: revoke + delete device row; local files stay on the user’s
  disk (they own them).

---

## 9. PR review checklist (paste into the PR)

- [ ] Flag off leaves existing behaviour
- [ ] New API key is restricted (or this PR does not mint keys)
- [ ] No `shell.exec` / server-supplied command
- [ ] Path or zip tests added if I/O changed
- [ ] Characterization diff empty
- [ ] Locales ×4 if copy changed
- [ ] Mobile policy updated if new `synaplan/` paths
- [ ] No secrets in the diff
- [ ] Docs updated in this PR
- [ ] `DS*`: flag-off behaviour asserted; harness updated if device-facing
- [ ] `DC*`: no `synaplan/` files (except `DC5` / `DC21` docs) and no frozen
  fixture was edited
