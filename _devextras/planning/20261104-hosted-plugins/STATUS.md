# Status — hosted plugins

Plan: [`00_master_plan.md`](./00_master_plan.md).
Rules: [`01_rules.md`](./01_rules.md).
Steps: [`02_steps.md`](./02_steps.md).

A walk is a dated entry here, not a chat message. §8 of the plan is
unticked; no step except the plan itself has started.

| Step | State | Example? | Notes |
| ---- | ----- | -------- | ----- |
| HP0 | written 2026-11-04 | — | this folder; §8 unticked. Copilot on #2987: disablement covers install, the installed list, and both asset routes; `PLUGINS.ENABLED` stays a separate per-user list; only plugin-only repositories are archived |
| HP1 | not started | synascriber is already shipped | homepage still the archived repo |
| HP2 | not started | — | needs D10 |
| HP3 | not started | — | needs D1, D9 |
| HP4 | not started | — | Synamail plugin; needs D3, D6, D11, D13 |
| HP5 | not started | — | blocked on a published Desktop release (draft `v0.1.0` does not count) |
| HP6 | not started | — | synafastbill |
| HP7 | not started | — | Synaads; needs PQ1 |
| HP8 | not started | — | Synaform series HP8a–g |
| HP9 | not started | — | WordPress rewrite, its own repository |
| HP10 | blocked | — | SortX; needs the security note below |

## Inventory (2026-11-04)

| Id | In the image | Gitignored | Source SHA we would snapshot | Blocker |
| -- | ------------ | ---------- | ---------------------------- | ------- |
| `synascriber` | yes, 0.4.1 | no | already on `main` | homepage pointer (HP1) |
| `synamail` | no | yes, line only; folder not on the workstation | metadist/Synamail `synamail-plugin/` at the import | French locale, no plugin tests, install-link target (HP4) |
| `synafastbill` | no | yes, line only | metadist/synafastbill `42323c2` is the public tip as of this note; re-check at import | curl client, French, tests live outside the plugin (HP6) |
| `synaads` | no | yes, line only | metadist/Synaads tip at import | size, no locales, no tests, `_devextras/test-synaads.sh` (HP7) |
| `synaform` | no (local gitignored copy only) | yes | metadist/Synaform `d98ae8d` (v4.4.3) as of this note; re-check at import | 11,885-line controller, 9,218-line page (HP8) |
| `sortx` | no | ignore line is `plugins/synasort/`, folder absent | private; no SHA until §6 | security pass (HP10) |
| desktop | companion | — | — | no published installer (HP5) |
| wordpress chat | companion | — | tag `1.0.10` is the old plugin, not the snapshot | calls `app.synaplan.com/api.php`, creates accounts (HP9) |

## Security note (SortX)

Not signed. HP10 stays blocked until this section has a date, the result
of the history scan, and the sentence "snapshot of `sortx-plugin/` at
`<sha>` is clean."

## Walks

None yet.
