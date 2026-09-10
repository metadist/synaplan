# Wave 5 architecture research (2026-09-10)

Two research questions raised before Wave 5 of
[`../20260903_roadmap.md`](../20260903_roadmap.md) starts. **Research only — no
code was changed.** Both documents were written against the verified state of
the `main` checkout on 2026-09-10 (see the "Evidence" sections; every number
was measured, not estimated).

| # | Question | Document | Verdict |
| - | -------- | -------- | ------- |
| 1 | Could the Wave 5 "agents in extra boxes" (track 5, Secure Compute) be a **headless Synaplan Desktop** installation on other servers instead of a new fleet inside `synaplan/`? | [`01_compute_vs_headless_desktop.md`](./01_compute_vs_headless_desktop.md) | **No** as an implementation; **yes** to the underlying instinct (keep it out of `synaplan/` — the track already does) and to reusing four Desktop patterns and a shared marketing story. |
| 2 | Should optional PHP features (Tika, Docling, widgets, Higgsfield, Google, Collabora, Stripe, IAP …) only be **loaded when configured** so an installation stays lean? | [`02_conditional_module_loading.md`](./02_conditional_module_loading.md) | **Yes, narrowly**: not "lazy-load PHP files" (the runtime already does that), but *declared feature modules* gated at runtime, dead-weight removal in `vendor/`, and a CI matrix that proves "absent means absent". Plan: [`../20260910-feature-modules/`](../20260910-feature-modules/00_master_plan.md). |

## Where these files belong

- Document 1 is a **`synaplan-platform` planning document** (it discusses
  Cloud topology and positioning). The agent's GitHub token cannot reach the
  private `metadist/synaplan-platform` repository (`gh` returns 404), so it is
  parked here. It contains no node names, IPs, credentials or private
  endpoints and is safe in this public repository; move it to
  `synaplan-platform/planning/20260910_compute_vs_headless_desktop.md` and
  leave a one-line pointer here when convenient.
- Document 2 and the implementation plan are `synaplan/` planning documents
  and stay in `_devextras/planning/`.

## Follow-ups this research asks of the product owner

1. Tick or reject the decision rows in
   [`01_compute_vs_headless_desktop.md`](./01_compute_vs_headless_desktop.md) §7
   (positioning + two additions to the compute A0 spike).
2. Tick the checklist in
   [`../20260910-feature-modules/00_master_plan.md`](../20260910-feature-modules/00_master_plan.md) §0
   before any code is written (roadmap §7 workflow).
