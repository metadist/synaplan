# Sprint B5 — Poll loop and unattended runs

**Phase B (`synaplan-desktop`), sprint 5 of 5.** Steps `DC19`–`DC21`,
plus `DC27` (autostart).

**Goal:** A job queued from Synaplan web is picked up by the paired computer on
its next check-in, runs **only** if the named skill is installed and enabled,
and reports back — against the contract that Sprint A3 already shipped and
froze. Nothing in this sprint changes the server (C9).
**Depends on:** Sprint B4 (a real skill is worth polling for) and `DS14`, which
has been on `main` since Phase A.
**Unlocks:** the "Waiting for this computer" experience end to end.
**Repos:** `synaplan-desktop`, plus one docs-only `synaplan/` PR (`DC21`).
**Platform rules:** [`13_cross_platform.md`](./13_cross_platform.md) §8
(autostart) and §4 (the plaintext-key restriction) are binding here.

---

## 0. Why this sprint exists — and why it is last

The server half of this loop shipped in Sprint A3 and was proved by the
fake-device harness (`DS17`). This sprint replaces the harness with a real
device. That ordering is the whole point of the server-first decision: the
contract was designed once, in review, and the client conforms to it. A client
wish is a `protocol: 2` conversation, not an edit (master plan decision 22).

It is last inside Phase B because unattended execution is the highest-trust
feature in the product. It should be built on a runtime whose confinement
corpus, doctor, and install flow have already been reviewed and shipped.

**This is also the cut line.** If scope slips, this sprint and `DS11`–`DS18`
are what get cut (decision 24). Pair, chat, install a skill, make a deck —
that is a coherent product without polling.

---

## 1. What already exists (do not re-negotiate)

| From | What |
| ---- | ---- |
| `DS14` | MCP `agent_checkin` and `agent_report_result`, requiring `desktop:jobs` |
| `DS18` | `protocol: 1`, closed `type` and error-code enums, committed fixtures |
| `DS12`/`DS15` | Lease, expiry, attempt budget, reaper |
| `DS16` | The web-side "Run on this computer" action and waiting card |
| `DC3` | Those same fixtures, vendored into the client's tests |

The client's unit tests are built from the vendored fixtures. If a fixture does
not match what the client wants, the client is wrong (C9).

---

## 2. Developer steps

### 2.1 The poll loop (`DC19`)

```
check-in  →  jobs + next_call_at  →  run  →  report  →  sleep until next_call_at
```

1. Call `agent_checkin` with `protocol: 1`, `agent_kind: "synaplan-desktop"`,
   `capabilities: ["skill.run"]`, and the list of **enabled** skill names.
2. Honour `schedule.next_call_at` exactly. Do not invent a shorter interval;
   add jitter only within the window the server allows.
3. For each leased job, validate **locally** before doing anything:

   | Check | Failure |
   | ----- | ------- |
   | `type == "skill.run"` | `unknown_type` |
   | skill installed | `unknown_skill` |
   | skill enabled | `skill_disabled` |
   | skill's required runtimes present (doctor, B4) | `local_error` with the missing tool named |
   | `allowUnattended` for this skill (§2.2) | `skill_disabled` |

4. **Read only `{skill, prompt, fileIds}` out of `input`.** Every other key is
   ignored by construction — the payload is parsed into a typed struct with
   those three fields, so an unexpected `command` or `argv` is not "filtered
   out", it is never read. This is the single rule that keeps a compromised or
   prompt-injected server from becoming remote code execution.
5. Run the job through **the same tool loop as Sprint B2**, with the same
   confinement, the same binary allowlist, and the same process controls. There
   is no second execution path for jobs.
6. Report with `agent_report_result`: status, optional `fileId` (upload through
   the files API first), and an error code from the frozen enum.
7. Network failures are retried with backoff and never crash the loop; a lease
   the client cannot report is left to expire server-side.

Refusals are **loud on the device and honest on the server**: the job is marked
failed with the right code, and the user sees why in the chat card.

### 2.2 Unattended opt-in (`DC20`)

Running a program while nobody is watching is a different consent level from
running one in response to a message the user just typed.

- `allowUnattended` is **per skill**, default **false**, stored in
  `skills.json`.
- Turning it on requires an explicit confirm that names the skill and says the
  program may run without anyone at the keyboard.
- There is no global "allow all skills unattended" switch.
- The first unattended run of a skill raises an OS notification naming the
  skill and the file it produced.
- The per-turn "this skill wants to run a program" dialog (B2 §2.5) is what
  `allowUnattended` replaces — so the confirm text must carry the same weight.
- **Unattended is refused when the key is in the plaintext fallback**
  ([`13_cross_platform.md`](./13_cross_platform.md) §4): a headless machine
  with a plaintext key that also runs programs unsupervised is not a
  configuration we ship.

### 2.3 Background operation and autostart (`DC27`)

For the loop to be useful the app has to be running. It must not become
something the user cannot see or stop.

| Platform | Mechanism |
| -------- | --------- |
| Windows | `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`, or a Task Scheduler logon task if we need delay/retry. No service, no `HKLM`, no elevation |
| macOS | `SMAppService` login item (LaunchAgent inside the bundle). The user can disable it in System Settings → Login Items, and the app must handle being disabled there without complaining |
| Linux | XDG autostart `.desktop` in `~/.config/autostart`, or a `systemd --user` unit. An AppImage path changes on update: write the resolved path at enable time and re-validate at start |

Rules on all three:

1. **Opt-in.** Never enabled by the installer.
2. Disabling it in the app removes the OS entry in the same action, and
   uninstalling removes it too. No orphaned autostart entries — that is how a
   desktop app earns a reputation as malware.
3. Single instance: a named mutex on Windows, `flock` on POSIX. Two poll loops
   would double-lease and double-run.
4. A tray/menu-bar item shows: connected or not, last check-in, jobs waiting,
   and **Quit**. Quit means quit — the loop does not resurrect itself until the
   next login.
5. Respect the OS: no console window on Windows, no dock icon when running
   background-only on macOS (`LSUIElement` when appropriate).
6. Sleep, hibernate, and network loss are normal. On resume, re-check in rather
   than assuming the schedule survived.

### 2.4 Do not do in this sprint

- Change anything under `synaplan/backend` (C9). `DC21` is docs only.
- Add a `protocol: 2` field "while we are in here".
- Push delivery. Centrifugo may later *hint* that work exists; check-in stays
  the source of truth.
- Job types beyond `skill.run`.
- Auto-install a skill named by a job. An unknown name fails, always.
- Run as a Windows service, a macOS daemon, or a system-wide systemd unit.

---

## 3. Tests

Offline, fixed clock, temp home, **all three runners**. The upstream is the
vendored `DS18` fixture set.

| Case | Expected |
| ---- | -------- |
| Job for an uninstalled skill | `unknown_skill`, no process spawned |
| Job for a disabled skill | `skill_disabled`, no process spawned |
| Job with `type: "shell.exec"` | `unknown_type` |
| Job whose `input` carries `command` / `argv` / `script` | fields never read; assert the spawned argv |
| Job for a skill whose runtime is missing | `local_error` naming the tool, no doomed loop |
| `allowUnattended` false | refused with `skill_disabled` |
| Plaintext-key fallback active | unattended refused before check-in |
| `next_call_at` honoured | no early poll |
| Check-in HTTP 500, then 200 | backoff, loop survives |
| Two instances started | second exits, single-instance guard |
| Autostart enable then disable | OS entry created then removed (per-OS assertion) |
| Report fails to send | lease left to expire, no duplicate run |

### 3.1 Manual (PR evidence)

On each OS, at least once: queue from web → file appears → chat shows the
completion. Plus one screenshotted refusal (uninstalled skill) so the failure
copy is reviewed, not assumed. Rows go into the
[`13_cross_platform.md`](./13_cross_platform.md) §11 table.

---

## 4. Exit criteria

1. Web queue → desktop run → file in chat, demonstrated on all three OSes.
2. A job naming an uninstalled or disabled skill fails closed, with no process
   spawned, and the server records the frozen error code.
3. No `synaplan/` code changed; the vendored fixtures still match Phase A byte
   for byte (C9).
4. Unattended is per-skill, default off, and refused with a plaintext key.
5. Autostart is opt-in and leaves nothing behind when disabled or uninstalled,
   on each platform.
6. `make ci-local` green on all three runners.
