# Status — loop and cloud-file review

Plan: [`00_master_plan.md`](./00_master_plan.md). A walk is a dated entry
here, not a chat message. §0 is not ticked. No fix starts until it is.

| Step | State | Note |
| ---- | ----- | ---- |
| RV0 | in review | This plan, against `main` `fa64e4c82` |
| RV1 | planned | Round mode drops `scope: files` |
| RV2 | planned | Messages session reuses `call-1` once the usage hook is `on` |
| RV3 | planned | Messages stream holds the database connection. Live today |
| RV4 | planned | Pickup copy leaves `BSOURCEINSTANCE` null. Live where platform links are on |
| RV5 | planned | A repeated listing spends the file limit again. Live, same |
| RV6 | planned | `delivered` is accepted from `queued`. Live, same |
| RV7 | planned | A watch does not record its own upload. Waits on `cloud_files` |
| RV8 | planned | An expired research lease pays the step twice. Waits on `RESEARCH.ENABLED` |

## 2026-10-28 — review

Read-only pass of rows 9, 21 and 22 on `main` at `fa64e4c82`. No model
was called. No flag was changed. #2853 (the architecture target
picture) landed after that read and does not change the findings.
The eight defects are in the master plan with the file and the test
that proves each one. Three things that looked like defects are not:
the bird-status cache generation, marking a missing chat delivered,
and a WebDAV folder of `..` on the person's own connection.

Rows 9, 21 and 22 in `ROADMAP.md` were behind that commit (cloud files
"no product code yet", research "plan only", architecture "AU4 in
progress"). The same change corrects those three state lines. Other
rows were not re-verified.
