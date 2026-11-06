# Status — hosted plugins

Plan: [`00_master_plan.md`](./00_master_plan.md).
Rules: [`01_rules.md`](./01_rules.md).
Steps: [`02_steps.md`](./02_steps.md).

A walk is a dated entry here, not a chat message. The owner moved HP4
first on 2026-11-05. D1, D3, D6, D9, D11 and D13 are ticked for that
step. D2, D4, D5, D7, D8, D10, D12 and D14 stay open.

| Step | State | Example? | Notes |
| ---- | ----- | -------- | ----- |
| HP0 | written 2026-11-04 | — | this folder. Copilot on #2987: disablement covers install, the installed list, and both asset routes; `PLUGINS.ENABLED` stays a separate per-user list; only plugin-only repositories are archived |
| HP1 | not started | synascriber is already shipped | homepage still the archived repo. Behind HP4 as of 2026-11-05 |
| HP2 | not started | — | needs D10. The "plugin disabled" half of J-HP-1 waits here |
| HP3 | not started | — | needs D1, D9, which are now ticked; still behind HP4 |
| HP4 | worked on 2026-11-05 | held to the example bar in this step | Synamail profiles. Snapshot of metadist/Synamail `a176caef5fd28e76ac60f401ca3c57b22fadbd51`, history not imported. Outlook add-in stays in that repository |
| HP5 | not started | — | blocked on a published Desktop release (draft `v0.1.0` does not count) |
| HP6 | hosted 2026-11-06, not yet an example | no | synafastbill is in `plugins/synafastbill`. Shared HTTP client replaces curl. French and the live FastBill walk are still open |
| HP7 | not started | — | Synaads; needs PQ1 |
| HP8 | hosted 2026-11-06, split still open | no | The running Synaform tree is in `plugins/synaform`. The controller and the page are still over the size bar |
| HP9 | not started | — | WordPress rewrite, its own repository |
| HP10 | blocked | — | SortX; needs the security note below |

## Inventory (2026-11-04)

| Id | In the image | Gitignored | Source SHA we would snapshot | Blocker |
| -- | ------------ | ---------- | ---------------------------- | ------- |
| `synascriber` | yes, 0.4.1 | no | already on `main` | homepage pointer (HP1) |
| `synamail` | this step, not on `main` yet | ignore line removed in this step | metadist/Synamail `a176caef5fd28e76ac60f401ca3c57b22fadbd51` (`synamail-plugin/` only) | French, tests and the docs install link are part of HP4 |
| `synafastbill` | yes, from the running copy (2026-11-06) | no | GitHub tip `42323c2` is current and has no open pull request. The imported tree had already diverged from that tip | French locale and the live FastBill walk |
| `synaads` | no | yes, line only | metadist/Synaads tip at import | size, no locales, no tests, `_devextras/test-synaads.sh` (HP7) |
| `synaform` | yes, from the running copy (2026-11-06) | no | GitHub tip `d98ae8d` (v4.4.3) is current and has no open pull request. The imported tree uses `AiFacade` and is not a byte match | controller and page still over the size bar (HP8 split) |
| `sortx` | no | ignore line is `plugins/synasort/`, folder absent | private; no SHA until §6 | security pass (HP10) |
| desktop | companion | — | — | no published installer (HP5) |
| wordpress chat | companion | — | tag `1.0.10` is the old plugin, not the snapshot | calls `app.synaplan.com/api.php`, creates accounts (HP9) |

## Security note (SortX)

Not signed. HP10 stays blocked until this section has a date, the result
of the history scan, and the sentence "snapshot of `sortx-plugin/` at
`<sha>` is clean."

## Walks

- 2026-11-05 — J-HP-1 on the dev stack, signed in as `admin@synaplan.com`.
  Plugins → Synamail (`http://localhost:5173/plugins/synamail`). Empty
  list: one sentence and **How to install in Outlook**, which points at
  `https://docs.synaplan.com/synamail#install-it`. A stored profile
  (Ada Example) opened, asked "Delete this profile permanently?" on the
  page, and the second click removed it. With `P_synamail.enabled` off,
  the page said "Profiles are unavailable. The profile service may be
  turned off." and showed no stack. Light, dark, and a 320 px width:
  the buttons wrap and the page does not scroll sideways.
  `PLUGINS_DISABLED` is still HP2, so that half of the journey is not
  walked. The plugin was installed locally for users 1 and 2; that is
  not a committed default.
