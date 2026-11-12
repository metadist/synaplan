# 06 — Housekeeping and duplicate labels

**Issue:** [#2680](https://github.com/metadist/synaplan/issues/2680) (bilogic).
Acceptance criteria on the issue are empty. These are the criteria.
**Commit:** `feat(models): mark models the provider no longer offers`
**Class:** `ota-candidate`.
**Branch:** `feat/20261024-model-housekeeping`
**Depends on:** sprint 02, because a later click on Remove must succeed
for a default. This sprint does not delete.

## Problem

The catalog keeps a model after the provider has withdrawn it. The admin
deletes rows one by one, and a default row cannot be deleted (sprint 02).
Two models with the same display name, from two accounts or two
providers, cannot be told apart.

The issue says the OpenAI protocol can say a model is unavailable or
suspended. Confirm that field on one real endpoint during the walk. If
the endpoint's `GET /v1/models` has no such field, absence from that
list is the signal. Do not invent a property name.

## Goal

Refreshing a provider's models marks each imported row that the provider
no longer lists. The row stays, with one sentence, until the admin
removes it. Two rows with the same name show which provider and which
endpoint they came from.

## User-flow

Pointer: AI models → the provider's Import models, which already has a
refresh of the list, and the catalog table.
Journey: **J-OI-5**.

1. Import two models from a test endpoint.
2. Make the endpoint's list omit one of them. Refresh.
3. The omitted row is still in the catalog, inactive, with the sentence
   below. The other row is unchanged.
4. Two catalog rows with the same name show the provider and the
   endpoint, not the name alone.
5. Remove still works, including when the inactive row is a default
   (sprint 02). This sprint does not press Remove itself except to
   confirm the label.

Exit (UX contract §6):

1. J-OI-5 walked against a local endpoint (Ollama or an OpenAI-compatible
   test double). Record the endpoint's model JSON in STATUS, with no
   secrets.
2. The marked row is on the catalog the admin already uses. No new page.
3. The sentence below, five locales. It says the model is not offered
   and that it was not deleted.
4. A provider that does not answer keeps today's unreachable state and
   marks nothing (a failed refresh must not mark every row withdrawn).
   No flag.
5. Light, dark, 320 px. The extra label wraps; it does not widen the
   table past the card.

## Copy

`config.aiModels.admin.withdrawn` =
"No longer offered by {provider}. It is still here until you remove it."

The duplicate label reuses the provider id and the endpoint name already
on the row. No new sentence if those two are visible. If the table hides
them, show "{name} · {provider} · {endpoint}" as the name cell, not a
second heading.

## Change

- On a successful **import preview / refresh**, compare listed ids with
  rows previously imported from that endpoint (import meta on the model).
  Ids missing from the list are **withdrawn**, not deleted.
- **Reuse the existing reconciliation path** where possible:
  `ImportedModelListingCheck` already re-lists sources on
  `app:model:health-check` and applies changes through
  `ModelAutoDisabler`, which honours `MODELHEALTH.AUTO_DISABLE_ENABLED`
  and records which rows automation disabled so operator toggles win and
  a returning model can be restored. Do not bypass that with a one-off
  `active = 0` in the preview handler — preview refresh and the
  scheduled check should share one disable/restore policy (same
  provenance rules, same `withdrawn` / health meta).
  - Store `withdrawn: true` (or the meta field the listing check already
    uses) so the admin sentence matches automation.
  - A listed id clears the withdrawn marker; it does **not** turn a row
    back on by itself when the admin had disabled it.
- A preview that fails the endpoint check writes no meta (same C7 rule as
  the listing check: unreachable source marks nothing).
- The catalog name cell shows provider and endpoint when another row
  has the same name.
- Sprint 02 applies when the admin removes a withdrawn default: effective
  `getDefaultModel('VECTORIZE')` after withdrawal follows the same note
  as in `02_delete_default_model.md`.

## Test

- Preview list without an id that was imported from that source: the
  row is inactive and `withdrawn` is set. A second row that is still
  listed is unchanged.
- Preview that returns endpoint not ok: no row gains `withdrawn`.
- Two models named "Echo", different services: the name cell includes
  the service. One model named "Echo": the cell is the name only.

The first test is red before the compare exists.

## Gate

`make ci-local` and `make test-e2e`. This is the only `feat` in the
track.
