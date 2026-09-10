# Wave 5 architecture research (2026-09-10)

Two research questions raised before Wave 5 starts, plus the 10 Sep partner
review. **Research only — no product code was changed.** Documents 1–2 were
written against the verified state of the `main` checkout on 2026-09-10
(see the "Evidence" sections; every number was measured, not estimated).

**Live roadmap:** [`../20260910_roadmap_update.md`](../20260910_roadmap_update.md)
— two production bugfixes first, then Wave 4, then **Intermezzo** (this
research's feature-modules plan), then Wave 5. The 2026-09-03 overview is
archived at [`../20260903_roadmap/`](../20260903_roadmap/README.md).

| # | Question | Document | Verdict |
| - | -------- | -------- | ------- |
| 1 | Could the Wave 5 "agents in extra boxes" (track 5, Secure Compute) be a **headless Synaplan Desktop** installation on other servers instead of a new fleet inside `synaplan/`? | [`01_compute_vs_headless_desktop.md`](./01_compute_vs_headless_desktop.md) | **No** as an implementation; **yes** to the underlying instinct (keep it out of `synaplan/` — the track already does) and to reusing four Desktop patterns and a shared marketing story. |
| 2 | Should optional PHP features (Tika, Docling, widgets, Higgsfield, Google, Collabora, Stripe, IAP …) only be **loaded when configured** so an installation stays lean? | [`02_conditional_module_loading.md`](./02_conditional_module_loading.md) | **Yes, narrowly**: not "lazy-load PHP files" (the runtime already does that), but *declared feature modules* gated at runtime, dead-weight removal in `vendor/`, and a CI matrix that proves "absent means absent". That work is the **Intermezzo** release, plan [`../20260910-feature-modules/`](../20260910-feature-modules/00_master_plan.md). |
| 3 | Partner review of the roadmap and next steps | [`03_architecture_and_steps.txt`](./03_architecture_and_steps.txt) | Insert reliability before expanding unattended work. The live roadmap answers the "between 3 and 4" insert with the two bugfixes first, and parks the larger reliability / workflow programme in Wave 5. |

## Where these files belong

- Document 1 is a **`synaplan-platform` planning document** (it discusses
  Cloud topology and positioning). The agent's GitHub token cannot reach the
  private `metadist/synaplan-platform` repository (`gh` returns 404), so it is
  parked here. It contains no node names, IPs, credentials or private
  endpoints and is safe in this public repository; move it to
  `synaplan-platform/planning/20260910_compute_vs_headless_desktop.md` and
  leave a one-line pointer here when convenient.
- Documents 2–3 and the Intermezzo implementation plan are `synaplan/`
  planning documents and stay in `_devextras/planning/`.

## Follow-ups this research asks of the product owner

1. Tick or reject the decision rows in
   [`01_compute_vs_headless_desktop.md`](./01_compute_vs_headless_desktop.md) §7
   (positioning + two additions to the compute A0 spike). Does not block
   Wave 4; needed before Wave 5 compute B1.
2. Tick the checklist in
   [`../20260910-feature-modules/00_master_plan.md`](../20260910-feature-modules/00_master_plan.md) §0
   before any Intermezzo code (roadmap workflow).
