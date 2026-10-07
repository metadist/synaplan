<!-- title: Nav: model setup is split between Admin → AI infrastructure (provider keys) and the Assistants rail → Models (endpoints, import, prices, defaults); seeded test and stub models are visible in production -->
<!-- type: Feature -->
<!-- labels: prio:3, area:nav, area:models -->
<!-- issue-type: Feature -->

## Summary
One Models area for administrators that holds provider keys, OpenAI-compatible endpoints, model import, prices, defaults and the embedding status card; and seeded test / stub models (negative ids) hidden outside development.

---

## Problem / Motivation
Model setup is split: provider keys live under Admin → AI infrastructure, while endpoints, model import, prices and defaults live under the Assistants rail → Models. Seeded test and stub models (negative ids) are visible in the production model catalog (F20). The embedding setup alone touches three screens (F19). Open WebUI keeps it on one admin page.

---

## Goal
An admin setting up a provider, an endpoint and the default models never leaves one area; a member who opens Models sees only what they may change.

---

## Acceptance criteria
- [ ] One Models area (location decided with the navigation-consolidation track — `_devextras/planning/20260914-navigation-consolidation/`): tabs Providers (keys), Endpoints, Catalog (Edit Models + Import), Defaults (Model Choice), Embeddings (status card).
- [ ] Member-facing model choice stays where members expect it (composer chip, personal defaults); the admin area is admin-only.
- [ ] Rows with negative ids / `stub` service are filtered out of every production listing (`APP_ENV !== 'dev'`), including `/config/models`.
- [ ] Old routes redirect; the command palette indexes the new tabs; docs updated in the same PR (copy-correctness rule).

---

## Notes
- Findings: F20, F19 (location) — community test round on 5.2.0. Related closed issue: #2335 "Move model selection".
- Code: routes for Admin → AI infrastructure and Assistants → Models, `backend/src/Seed/` (stub / test model rows), `ConfigController` `/config/models`.
- Journey (U10): admin adds an OpenRouter key → same area → Endpoints → Import → Defaults → done without a rail change.

---

## Screenshots/Logs
—
