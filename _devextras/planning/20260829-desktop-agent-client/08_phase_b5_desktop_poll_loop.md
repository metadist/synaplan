# Sprint B5 — Desktop poll loop and web-queued jobs

**Phase B (`synaplan-desktop`), sprint 5 of 5 — the last sprint of the epic.**
Steps `DC19`–`DC21`.

**Goal:** The desktop polls the check-in endpoint that already shipped in
Sprint A3, runs only a **named, installed, enabled** skill, and reports a
result. The web turn does **not** wait.
**Depends on:** Sprint B4 (a real skill to run) and the frozen A3 contract.
Checklist rows 9, 10, 22. July research §1 and §4 (why pull; why not in-turn).
**Repos:** `synaplan-desktop` only. **No server PR belongs in this sprint** —
if you feel one coming, see §0.1.
**Server side:** [`03_phase_a3_jobs_and_checkin.md`](./03_phase_a3_jobs_and_checkin.md).

---

## 0. Why this sprint is small

Because the server-first order already paid for most of it. The queue, the
lease, the reaper, the enqueue endpoint, the “Waiting for this computer” card,
and the two MCP tools are live on `main` and covered by the fake-device
harness. What is left is the client loop and the local refusal rules.

### 0.1 The contract is input, not negotiable

`protocol: 1` and the fixtures in
`synaplan/_devextras/testing/desktop/fixtures/` (`DS18`) are the specification.
Invariant **C9** says Phase B does not change them.

| Situation | Do |
| --------- | -- |
| A field is awkward to consume | Adapt in the client |
| A field is missing for a v1 feature | Check the fixtures again; it is probably there under another name |
| A field is genuinely absent and needed | Stop. Write it up as `protocol: 2` with a server migration plan, get it agreed, then implement — in a **server** PR, in a **separate** sprint |
| The server sends something unexpected | Ignore it. Never execute it. Report a clean error |

“I edited the server a little to make the client simpler” is how this epic
gets a `command` field. That is exactly what the ordering exists to prevent.

---

## 1. Code / specs to read first

| Source | Why |
| ------ | --- |
| `07-AGENT-SCHEDULING.md` §4 | Response shape, lease, idempotency, backoff |
| `synaplan/docs/DESKTOP.md` (`DS18`) | The shipped contract, in prose |
| `synaplan/_devextras/testing/desktop/fixtures/` | The bytes your tests assert |
| `synaplan/_devextras/testing/desktop/fake-device.sh` (`DS17`) | The reference sequence, already proven green |
| Sprint B2 tool loop | Reuse; do not fork a second executor |

Read the harness before writing the loop. It is a working client in 200 lines
of shell; the Tauri version should make the same calls in the same order.

---

## 2. Developer steps

### 2.1 Poll loop (`DC19`)

Background task (window open in v1; tray is a follow-up):

1. Sleep until `next_call_at` (or 30 s the first time). Respect jitter.
2. Call `agent_checkin` with `protocol: 1`, `agent_kind: "synaplan-desktop"`,
   `capabilities: ["skill.run"]`, and the enabled skill names.
3. For each job: if `type != skill.run`, or the skill is not installed, or it
   is installed but disabled → `agent_report_result` failed with
   `unknown_type` / `unknown_skill` / `skill_disabled`. **No subprocess.**
4. Else run the **same** tool loop as interactive chat, with the job prompt as
   the user message and that skill forced into context.
5. Report success or failure, with a `fileId` if the skill produced a file
   (upload through the existing files API first).
6. On network failure: exponential backoff, never a tight retry loop. A
   revoked key (401) stops the loop and shows the disconnected state.

**Read only `{skill, prompt, fileIds}` from `input`.** Ignore every other key,
including one named `command`, `script`, or `argv` — even if a future server
bug sends it (`11_security_and_compatibility.md` §4).

### 2.2 Unattended policy (`DC20`)

A queued job runs while the user is not looking. That deserves its own
consent, separate from “I installed this skill”:

- `allowUnattended` per skill in `skills.json`, **default false**.
- Default false means a queued job for that skill waits for a click
  (notification → approve) rather than failing.
- The first unattended run of a skill raises an OS notification naming the
  skill and the out-box folder.
- Never a global “allow everything unattended” switch.

### 2.3 End-to-end evidence and docs (`DC21`)

1. Manual run against a real instance: queue from web chat → the desktop
   picks it up → a `.pptx` lands in the out-box → the chat shows the file.
2. Screenshot both ends. Note the OS and which model answered.
3. Repeat the refusal case with an uninstalled name; screenshot the failed
   card in web chat.
4. Docs: extend `synaplan/docs/DESKTOP.md` with the queue walkthrough
   (docs-only `synaplan/` PR — the one allowed exception, as in `DC5`).

### 2.4 What we still will not do

- Resume a mid-flight `DagExecutor` plan.
- Accept a `shell.exec` type or any server-authored command string.
- Push-only delivery (Centrifugo may *hint*; check-in remains required).
- Tray-only daemon, planner-emitted jobs, `browser.*` jobs. Follow-ups.

---

## 3. Tests (client repo, all offline)

Build these from the vendored Phase A fixtures, not from hand-written JSON.

| Case | Expected |
| ---- | -------- |
| Mock check-in with `skill.run` / `pptx` enabled | Loop invoked with that prompt, one report call |
| `skill.run` / `not-installed` | Report `unknown_skill`, **no Bash** |
| `skill.run` / installed but disabled | Report `skill_disabled`, no Bash |
| Unknown `type` | Report `unknown_type`, no subprocess |
| `input` contains `command: "rm -rf /"` | Key ignored; assert the spawned argv never contains it |
| `allowUnattended: false` | Job waits for approval; no silent run |
| 401 during check-in | Loop stops, disconnected copy shown |
| Malformed / unknown `protocol` in the response | Back off, do not guess |
| Report upload failure | Job reported failed with `local_error`, no crash |

---

## 4. Exit criteria (and the epic’s)

1. Manual evidence: web queue → desktop run → file in chat.
2. Uninstalled and disabled skill names fail closed on the device, with the
   error codes the A3 contract defines.
3. Ignored-extra-key test is in CI.
4. Unattended default is false and the first run notifies.
5. Revoked device stops polling.
6. `make ci-local` green; no `synaplan/` change except the `DC21` docs PR.
7. Invariants C2, C3, C4, C5, C9 named in the PRs.

After this sprint the epic is feature-complete; the remaining work is the GA
flag decision in master plan §11.
