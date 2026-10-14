# Code cleanup V1 — status

Numbers to carry forward in every row: files in `src/Service/` root
(start 73, target 4) and files in `deptrac.baseline.yaml`. The first run
measured **12 files / 59 edges**, not the 9 the `rg` count in
`00_master_plan.md` §1 predicted: three more files
(`DTO\Bundle\BundleDocument`, `DTO\Bundle\BundleImportRequest`,
`Entity\InboundEmailHandler` — see the C1 row). C5 removes the two
`AccountLanguage` entries, leaving **10**.

| Date | Step | PR | Root files | Baseline entries | Note |
| ---- | ---- | -- | ---------- | ---------------- | ---- |
| 2026-10-14 | C0 | — | 73 | n/a | Plan drafted. §0 #1 decided: deptrac (new dev dependency + CI step approved). C1 waits for the next merge to `main`, then starts on explicit go |
| 2026-10-14 | C1 | [#2478](https://github.com/metadist/synaplan/pull/2478) | 73 | **12 files / 59 edges** | deptrac 4.7.2 as dev dependency; `make -C backend deptrac` in `ci-local` and the CI Backend job; red on a deliberate `Entity → Service` import, verified and reverted. Baseline reviewed line by line. **Findings beyond the nine files of §1** (frozen, not fixed — V1 rule 5, no behaviour change): `DTO\Bundle\BundleDocument` → `App\Bundle\BundleEnvelopeValidator` and `DTO\Bundle\BundleImportRequest` → `App\Bundle\ImportOptions` (Shared → Application; the two value types belong next to the DTOs, V3 list), and `Entity\InboundEmailHandler` → `Service\EncryptionService` (same shape as `McpServerConfig`, §1 counted only two Entity → Service files). **Layer-regex adjustment:** `App\Exception\` (two pure exception classes from the live-voice track, no `App\` imports) added to the Shared collector — the plan's regexes left it uncovered. `App\ApiResource\` is an empty directory and needs no layer |
