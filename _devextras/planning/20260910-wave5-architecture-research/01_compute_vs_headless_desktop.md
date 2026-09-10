# Research: Secure Compute as a headless Synaplan Desktop? (2026-09-10)

**Intended home:** `synaplan-platform/planning/` (private). Parked in
`synaplan/_devextras/planning/` because the research agent has no access to
that repository; the text contains nothing private. See
[`README.md`](./README.md).

**Question (product owner):** Wave 5 includes "agents in extra boxes" —
track 5, Secure Compute, sprints B1–B3. Instead of mixing a fleet of agents
into the `synaplan` repository, could that be an extra *"secure code
execution"* function of a **headless Synaplan Desktop** installed on other
servers? Strong marketing angle.

**Inputs read:** roadmap (`20260903_roadmap.md`), the ticked compute master
plan and A0 sprint (`202609_secure_compute/`), the desktop master plan
(`20260829-desktop-agent-client/00_master_plan.md`, rows 1–32, §5, §12),
`docs/DESKTOP.md`, and the public `metadist/synaplan-desktop` repository
(`README.md`, `AGENTS.md`, `docs/LOCAL_TOOLS.md`, `src-tauri/synaplan-core/src/*`,
`scripts/no-shell-guard.sh`, `skills/bundled/`), plus the tools/approval
master plan for `code_run`.

---

## 1. Short answer

**Do not make Synaplan Desktop the compute runtime.** The two products are
built on *opposite* trust models, and the thing that makes Desktop safe to
install on a private computer — "the device never receives code, only the name
of a skill the user installed" — is exactly the thing compute must not have.
Merging them would weaken Desktop's strongest claim and give compute a weaker
boundary than the one already decided (hardened container, gVisor on Cloud).

**The instinct behind the question is right and is already satisfied.** The
compute track (decisions 1 and 2, ticked 2026-09-03) puts the runtime in a
**separate repository and binary** (`synaplan-compute`, Go). `synaplan/` only
gains a thin HTTP `ComputeClient`, one flag, one env URL, the `code_run` tool
and a run card. Nothing "agent-fleet"-like lands in the PHP monolith. If that
was the worry, it is addressed by the existing plan; the only thing to add is
to say it out loud in the roadmap's vocabulary (§6): *"compute node" = a
server that runs `synaplan-compute`; never a Desktop install.*

**Borrow, don't merge.** Four Desktop patterns should be lifted into compute
(§5), and the two products should be marketed as one family story with two
clearly separate promises (§6).

---

## 2. What the two things actually are

| Dimension | Synaplan Desktop (shipped 1.0 preview, public) | Secure Compute (track 5, decided, not started) |
| --------- | ---------------------------------------------- | ---------------------------------------------- |
| Purpose | The AI uses *the user's own computer and files* through skills the user installed | The AI runs *code it wrote itself* on files the server pushed in |
| Who authored the executable thing | The user (or a trusted skill author) — a skill installed on the device | The LLM, per request — arbitrary Python/Node/`sh` |
| Wire payload to the runner | `{skill, prompt, fileIds}` — closed enum `skill.run`, no code, no shell string (master plan row C12: "the one rule that makes RCE structurally impossible") | `{workspace, image, entry{program,args[]}, files[], limits, egress}` — a script *is* the payload; `sh` allowed *inside* the sandbox (row 8) |
| Safety boundary | User trust step + binary allowlist of absolute paths + constructed environment + path confinement corpus + process-tree kill + `no-shell-guard.sh` in CI | Kernel/container boundary: `--network none`, read-only rootfs, tmpfs, `cap-drop ALL`, seccomp, non-root, pids/mem/CPU limits; T2 = gVisor, T3 = microVM (rows 3–4) |
| Tenancy | One device ↔ one user (pairing, scoped keys `desktop:*`) | One compute node ↔ *all* users of an instance; per-user quotas and workspaces (rows 7, 11) |
| Direction | **Pull**: device polls MCP `agent_checkin` / `agent_report_result`; no inbound port on the device | **Push**: PHP calls `COMPUTE_URL` over HTTP (row 2); the node is a service |
| Secrets | OS secret store; the poll loop *refuses* to run with the headless-Linux plaintext fallback | No Synaplan credentials on the node at all; push in, pull out (row 5) |
| Network from the runner | Only to the Synaplan server it paired with | None by default; per-run allow-list later (B3) |
| Shape | Tauri 2 GUI + tray + Vue 3 + Rust `synaplan-core`; per-OS installers, unsigned preview | Single static binary + Docker/containerd/`runsc` handle; compose profile / dedicated node |
| Language | Rust | Go (row 1; Rust "acceptable alternative") |
| Contract | `protocol: 1`, frozen, committed fixtures | `protocol: 1`, to be frozen after Phase A with fixtures (row 8) |
| Explicit scope statements | Desktop master plan §12 excludes "server-side execution of scripts" and "a permanent reference daemon in `synaplan/`" | Compute master plan names Desktop as "the client-side answer … §12 explicitly leaves server-side execution out. This track is the server-side counterpart with a container boundary instead of a user's trust step" |

The two teams already wrote the same sentence from both sides. The plans are
consistent; the question is whether to overrule them.

---

## 3. Why "headless Desktop as the compute node" does not hold

Evaluated as if we did it: install `synaplan-desktop` on a Linux server
without a display, pair it to the instance with a service account, and add a
`code_run` skill.

1. **It inverts the Desktop trust model.** To run LLM-written code the device
   would have to accept a code payload (or a shell string). That deletes
   C12/`no-shell-guard.sh`, the closed `skill.run` enum and the
   `{skill, prompt, fileIds}` rule — the exact properties the README and
   `docs/LOCAL_TOOLS.md` advertise. A single binary cannot be both "structurally
   cannot receive code" and "runs whatever the model writes". Users who install
   Desktop on their laptop would be running a binary whose *other mode* is a
   code executor; reviewers and security-minded customers will read it that
   way.
2. **It has no isolation boundary of its own.** Desktop's confinement is
   path-based and process-based on a machine the *user* trusts. Compute needs
   `--network none`, read-only rootfs, seccomp, cgroups, gVisor — i.e. a
   container runtime. A headless Desktop would still have to spawn hardened
   containers, so the container runner (the actual hard part) is built either
   way; Desktop would merely be a heavier wrapper around it.
3. **Wrong tenancy.** A compute node serves every user of an instance with
   per-user quotas, run audit (`BCOMPUTERUNS`) and persistent user workspaces.
   Desktop is paired to one user with `desktop:*` scopes; the server half
   (`docs/DESKTOP.md`) has no notion of "a device acting for many users".
   Adding that means a new pairing model, new scopes, and new authorization
   code in `synaplan/` — more PHP, not less.
4. **Headless is currently refused by design.** The poll loop declines to run
   when the only available secret store is the plaintext fallback on headless
   Linux. Lifting that for servers is a Desktop decision in its own right
   (§4), independent of compute.
5. **Wrong shape for a server.** Tauri + WebView + tray on a server pulls in a
   desktop toolkit and a GUI dependency tree; ops teams want a static binary
   with a health endpoint, metrics and a compose profile. The compute plan
   already specifies that.
6. **Frozen contract collision.** Desktop's job contract is frozen at
   `protocol: 1` with fixtures and a fixture-driven test harness
   (`_devextras/testing/desktop/`). Adding a code-run job kind is a
   `protocol: 2` break for every installed client, while compute has its own
   contract that needs freedom until Phase A3.
7. **Release cadence.** Desktop follows installer/store cadence (unsigned
   preview today, signing and stores later). A compute node needs to move with
   the backend image (`ci.yml` publish/pin/manifest chain) — a different
   release train.
8. **The decision is already taken.** Compute rows 1, 2 and 14 (own repo, PHP
   never touches Docker, T2 on a separate node for Cloud) are ticked. Reopening
   them costs the A0 spike its baseline.

**What the question gets right:** the fear of "a fleet of agents inside the
synaplan repo". Verified against the plan: the `synaplan/` footprint of track 5
is a client, a flag, a tool, a run card, two tables and a Feature-status row.
The fleet lives in `synaplan-compute`. That is the same boundary Desktop uses
(server half small, runtime elsewhere) and the same one Office docs chose
("never exec `soffice` in PHP; sidecar over HTTP").

---

## 4. A legitimate, separate idea hidden in the question: "Desktop headless mode"

The phrase "headless synaplan-desktop installation on other servers" describes
a real Desktop feature that is *not* compute: a **team machine** (an office
box with LibreOffice, a print server, a scanner PC) that runs Desktop as a
service and offers *installed, named skills* to a group — "convert this to PDF
on the office machine", "print this", "put it in the shared scanner folder".
That keeps every Desktop invariant (skills only, no code, user-installed,
allowlisted binaries) and only changes: who the device is paired to (a group
or service identity instead of a person) and how secrets are stored on a
headless host (a root-only secrets file or systemd credentials instead of a
keychain).

This belongs in the **Desktop** backlog (`20260829-desktop-agent-client`), is
`store-required`-free (Linux service, no app store), and is worth noting as
a future item. It must not be confused with compute in planning or marketing.

---

## 5. What compute should borrow from Desktop

These are cheap, proven in Desktop, and strengthen the compute plan without
touching its ticked decisions. Proposed as additions to the A0 spike
(`01_phase_a0_spike_and_threat_model.md`).

| # | Desktop pattern | Where it lands in compute |
| - | --------------- | ------------------------- |
| B1 | **Frozen contract with committed fixtures and a fixture-driven harness** (`_devextras/testing/desktop/`) | Already row 8; add "fixtures land in `synaplan/_devextras/testing/compute/` so PHP tests never need a live node" to A3 |
| B2 | **`no-shell-guard.sh` as a CI grep** — the codebase cannot contain a shell-spawning call outside one audited module | `synaplan-compute` CI: forbid `os/exec` outside the runner package; `synaplan/` CI: forbid any `exec`/`shell_exec`/`proc_open` in `ComputeClient` paths (the plan's row 2 as a test, not a sentence) |
| B3 | **Constructed environment + process-tree kill + wall-clock kill** | Already in row 3 for containers; also apply to the sidecar's own child processes (image pulls, artefact packing) |
| B4 | **Pull-model node registration as an *option*** — Desktop devices need no inbound port. For hosters who run compute on a separate node behind NAT, a `synaplan-compute` that *polls* the instance for runs (like `agent_checkin`) removes the need to expose `COMPUTE_URL` inbound on the node | Add to A0 as an evaluation item, not a decision: "push (`COMPUTE_URL`) is v1; record whether a pull mode is cheap enough to keep the door open (single interface in PHP, two transports later)". Cloud with T2 on a dedicated node is the first beneficiary |

Not to borrow: the pairing-per-user model, skill catalogue, Tauri shell, OS
secret stores.

---

## 6. Marketing: one family story, two promises

The market confuses "AI can run code" products; Synaplan can own a clear
distinction that competitors (hosted "code interpreter" features) cannot
claim: **the work happens where your files already are — on your computer or
in a locked room on your server — never in a vendor's cloud you do not
control.**

| | Synaplan Desktop | Synaplan Compute (working name) |
| - | ---------------- | ------------------------------- |
| One-line promise | "The AI works *on your computer*, with your files, using tools you installed — and it can never receive code." | "The AI works *in a locked room on your server*: it writes and runs the code, the room has no internet, and only the result files come out." |
| Audience | Individuals, knowledge workers, privacy-first SMEs, people with local files and local software (LibreOffice, scanners, printers) | Teams and companies on self-host or Synaplan Cloud who want charts, recalculated spreadsheets, conversions, generated documents without installing anything |
| Trust statement | Open source, Apache-2.0, skills you can read, no shell, no code payload, pairing you can revoke | Ephemeral hardened containers, gVisor on Cloud, no secrets inside, quotas and a run audit an admin can read |
| Commercial angle | Free, drives adoption and Cloud sign-ups; a reason to pick Synaplan over a hosted chat | Cloud tier feature (quotas per plan); self-host differentiator ("your own sandbox"); hoster upsell (dedicated compute node) |
| Naming risk | Do not call Desktop "an agent that runs code" | Do not call Compute "Desktop on a server" |

Message architecture proposal for the website and docs:

1. Family claim: **"Synaplan does the work — where your files live."**
2. Two tiles under it, Desktop and Compute, each with the one-line promise
   above and a "how it stays safe" paragraph written in the trust language of
   the respective plan.
3. One comparison table (the two "Trust statement" rows) — the table itself is
   the marketing asset; it shows that Synaplan *thought about* the boundary,
   which is the conversation security-minded buyers want to have.

Muddling the two (one binary, one name) would cost both claims: Desktop could
no longer say "never receives code", Compute could no longer say "no user
software, no user machine involved".

---

## 7. Decisions requested

| # | Decision | Proposed | Agree? |
| - | -------- | -------- | ------ |
| 1 | Secure Compute stays as decided (own repo `synaplan-compute`, Go, container/gVisor boundary). Synaplan Desktop is **not** the compute runtime. | Confirm | ☐ |
| 2 | Add vocabulary to roadmap §6: **compute node** = server running `synaplan-compute`; never a Desktop install. | Add | ☐ |
| 3 | Add B1–B4 from §5 to the compute A0 sprint as spike items (B4 as evaluation, not decision). | Add | ☐ |
| 4 | Record "Desktop headless mode for team machines" (§4) as a *Desktop* backlog item, explicitly separate from compute. | Record | ☐ |
| 5 | Adopt the two-promise positioning in §6 as the marketing brief for the Wave 5 launch material; name the compute product before B1 (working name "Synaplan Compute"). | Adopt | ☐ |
| 6 | Move this document to `synaplan-platform/planning/` and reference it from the compute `STATUS.md` review log. | Move | ☐ |

---

## 8. Evidence (verified 2026-09-10)

- `202609_secure_compute/00_master_plan.md` §0 rows 1, 2, 3, 4, 5, 8, 14 and
  the "Related" note on Desktop; `STATUS.md`: all A/B steps "planned".
- `20260829-desktop-agent-client/00_master_plan.md` rows C9/C12, §5, §12.
- `metadist/synaplan-desktop`: `README.md` ("pull, not push"; device input
  `{skill, prompt, fileIds}`), `docs/LOCAL_TOOLS.md` (tools as
  `{program, args[], workdir}`, absolute-path allowlist, constructed
  environment, process-tree kill, "no shell, ever"), `AGENTS.md` (contract
  frozen `protocol: 1`, plaintext secret fallback refused by the poll loop on
  headless Linux), `scripts/no-shell-guard.sh`, `src-tauri/synaplan-core/src/`
  (`poll.rs`, `pairing.rs`, `tools.rs`, `skills.rs`, `filesystem.rs`).
- `docs/DESKTOP.md`: server half, `DESKTOP_AGENT.ENABLED`, scopes
  `desktop:messages|mcp|files|jobs`, harness `_devextras/testing/desktop/`.
- `20260903_roadmap.md` §3 (W5 = track 4 S5–S6, track 5 S3–S5), §4 (sidecars
  behind versioned HTTP contracts, PHP never spawns processes), §6 vocabulary.
