<!-- title: Models: the Import models dialog lists ~460 OpenRouter models with no search, filter or sort, opens with "Select all new" ticked ("Import 461"), and forgets capability probe results on close -->
<!-- type: Feature -->
<!-- labels: prio:2, area:models -->
<!-- issue-type: Feature -->

## Progress
The import dialog has a live filter on name and model id. Select all applies to the rows that filter leaves visible. New rows are still pre-selected, and probe results are unchanged.

## Summary
The Import models dialog gets search (name, provider, model id), filters (provider / family, capability, already-imported), alphabetical and provider sort, opens with nothing selected (or confirms before importing more than a handful), and keeps capability probe results with a timestamp so closing the dialog does not throw away a billable probe.

---

## Problem / Motivation
Import from OpenRouter returns about 460 models with no search, provider filter, capability filter or sort (F4; first seen on 5.0.6). The dialog opens with "Select all new" already ticked and the button reading "Import 461" — one click on the obvious button adds every model the provider offers (F18, re-confirmed: "Import 458" when importing one model). Capability probe results ("Check what each model can do") vanish when the dialog is closed and reopened, forcing a repeat of a possibly billable probe (F5).

---

## Goal
Importing one model from a big provider takes three actions: search, tick, Import 1. Probing once is enough for a day.

---

## Acceptance criteria
- [ ] Search box (name, id, provider prefix) filtering the list live; filter chips: provider / family (from the id prefix, e.g. `openai/`, `anthropic/`), capability (when probed or when the listing says), "hide already imported"; sort: name, provider, price (once prices are imported).
- [ ] Opens with nothing selected; "Select all shown" acts on the filtered list; importing more than 10 models asks for confirmation with the count and the consequence ("461 models will appear in the model menu for everyone").
- [ ] Probe results are stored server-side per provider id with `probedAt`; the dialog shows them on reopen with "probed 2 h ago" and a Re-probe action; a TTL (e.g. 7 days) and a cost note before re-probing.
- [ ] The Edit Models table's existing search is reused where sensible (one filter component).
- [ ] Five locales; dark theme; keyboard: type-to-search on open.

---

## Notes
- Findings: F4, F5, F18 — community test round on 5.2.0.
- Code: `frontend/src/components/admin/plugs/ModelImportDialog.vue` (`selectAllNew`, `probe`, `probeCostNote`), `backend/src/AI/Import/ModelDiscoveryService.php` (listing), the probe endpoint, `ModelDiscoveryStateStore.php` (a natural home for cached probe results).
- Prices in the list depend on the OpenRouter price import issue; sort by price can land after it.
- Journey (U10): Import models → type "qwen" → 6 rows → tick one → Import 1 → row appears in Edit Models → reopen → probe results still there with the timestamp.

---

## Screenshots/Logs
Button text (5.2.0): "Import 461" / "Import 458" on open.
