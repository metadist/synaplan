# 02 — A default model can be removed

**Issue:** [#2679](https://github.com/metadist/synaplan/issues/2679) (bilogic).
The owner's note on the issue: move the default first, then delete.
**Commit:** `fix(models): move the default before deleting a model`
**Class:** `ota-candidate` (the API shape and the toast).
**Branch:** `fix/20261024-delete-default-model`

## Problem

`AdminModelsService::deleteModel` counts `BCONFIG` rows with
`BGROUP = DEFAULTMODEL` and `BVALUE` equal to the model id. One such row
throws `ModelConflictException`: "Model is referenced by DEFAULTMODEL
configuration. Change defaults first before deleting." The provider has
already dropped the model. The admin cannot refresh the catalog past it
and cannot remove the row.

## Goal

Delete always removes the row. Every default that pointed at it points
at another model of the same job before the row goes. When no other
model can take the job, that default is cleared and the screen says so.

## User-flow

Pointer: AI models → Edit models → the row's delete.
Journey: **J-OI-2**.

1. Sign in as admin. Set Chat's instance default to a model you can
   spare. Confirm a second chat model exists.
2. Delete the default model.
3. The row is gone. The toast names the model Chat now uses. The Chat
   picker shows that model.
4. Repeat with a model that is the only one for its job: the row is
   gone, and the toast says that job has no model until you pick one
   on this page.
5. Search (the embedding default): the stored binding is cleared, not
   reassigned to another model on this page. See the rule below for what
   runtime resolution may still do until the admin picks Search again.

Exit (UX contract §6):

1. J-OI-2 walked.
2. The new default is the selected model on the same AI models page.
3. Kind-specific sentence, five locales, written below. It says what
   moved and, when a job was cleared, that nothing was assigned.
4. Delete of a missing id stays "not found". A model that is not a
   default still deletes with the existing "deleted" sentence. No flag.
5. The toast uses the existing notification. Check light and dark.

## Rules

- **Find every binding** that points at this model before moving anything.
  Today `deleteModel` only counts `BCONFIG` rows whose `BVALUE` is the
  numeric id string. That misses group defaults and catalog keys.
  - Every `BCONFIG` row in group `DEFAULTMODEL` whose stored value
    resolves to this id through
    `GroupPolicyService::modelIdFromStored()` (numeric id **or**
    catalog key such as `groq:…:chat`).
  - Every `BGROUPCONFIG` row in group `DEFAULTMODEL` whose stored value
    resolves the same way (group policy uses the same resolver chain as
    `ModelConfigService::eachDefaultModelId()`).
  - Include per-user overrides (`BOWNERID ≠ 0`) and owner `0`.
- **Replacement** (every binding except `VECTORIZE`, see below): pick
  another model for the same **setting** (the `BSETTING` / policy key,
  e.g. `CHAT`, `PLAN`, `TOOLS`, `SUMMARIZE`).
  - Do **not** use `PickerCapabilities::forTag()` on the deleted model
    alone. That helper describes picker columns, not every
    `DEFAULTMODEL` slot: a chat-tagged model lists `CHAT`, `SORT`, and
    `ANALYZE` but seeded rows also use `PLAN`, `TOOLS`, and `SUMMARIZE`.
    Use the same setting → tag map as
    `ModelConfigService` (`CAPABILITY_TAGS` / emergency fallback), then
    require the candidate to fit that setting the way
    `ModelAlternatives::fits()` does (tag + features).
  - Candidate must be active, selectable, not retired, not the deleted
    id, not marked offline in the health table, and
    **`ModelConfigService::isConfiguredModelUsable()`** (same bar as
    `ModelAlternatives::forModel()` — a row with no provider key is not
    a valid replacement).
  - Same service first, then any other service. For a user-owned row,
    skip models that user's group policy forbids; if none remain, clear
    that row.
- **`VECTORIZE`**: never write a replacement id here. Switching the
  embedding model rebuilds the search index; the health UI already
  treats embed rows that way (`ModelAlternatives::defaultCapability`
  returns null for vectorize). **Clear** the stored binding only.
  - After the row is gone, `getConfiguredDefaultModel('VECTORIZE')`
    must be null. **`getDefaultModel('VECTORIZE')` may still return
    another embedding** via `firstUsableModelForCapability()` — that
    is today's runtime behaviour and also affects sprint 06 when a
    row is marked withdrawn. The toast and J-OI-2 step 5 must say that
    Search has no default **on this page** until the admin picks one;
    do not imply the stored default moved. Add a regression test on
    both methods after delete.
- Then delete the model, in the same flush. A failure leaves both the
  model and the old defaults in place.
- Do not start an embedding reindex.

## Copy (review before the Vue file)

`config.aiModels.admin` in all five locales.

| Key | English |
| --- | ------- |
| `deletedMoved` | Removed. {job} now uses {model}. |
| `deletedMovedMany` | Removed. The jobs that used this model now use another available one. |
| `deletedCleared` | Removed. {job} has no model until you pick one on this page. Nothing else was changed. |
| `deletedMovedAndCleared` | Removed. Some jobs now use another model. {job} has no model until you pick one on this page. |

`{job}` is the existing capability label (Chat, Search, …), not the
setting key. One cleared job is named. More than one cleared job uses
"Some jobs" in `deletedMovedAndCleared` and does not list keys.

A delete that moves nothing and clears nothing keeps `modelDeleted`.

## API

`DELETE /api/v1/admin/models/{id}` returns 200:

```json
{
  "success": true,
  "moved": [{ "setting": "CHAT", "scope": "global", "ownerId": 0, "modelId": 12, "modelName": "…" }],
  "cleared": [{ "setting": "VECTORIZE", "scope": "global", "ownerId": 0 }]
}
```

Both arrays are always present. OpenAPI lists them. Regenerate the
frontend schema in this pull request.

## Test

A new unit test beside the service (the service has no delete test
today). It fails on current `main` because delete throws
`ModelConflictException`.

- A `CHAT` default and a second active chat model: delete succeeds, the
  config value is the other model's id, the row is gone.
- The same, with no other chat model: delete succeeds, the config row
  is gone.
- A `VECTORIZE` default and another embedding model: delete succeeds,
  the vectorize row is gone, the other embedding model was not written,
  `getConfiguredDefaultModel('VECTORIZE')` is null; assert
  `getDefaultModel('VECTORIZE')` separately (documented, not silently
  assumed to match the toast).
- A `TOOLS` default (chat capability, not in `PickerCapabilities::forTag`
  for CHAT alone) with another usable chat model: the binding moves, not
  clears.
- A default stored as a **catalog key** string, not a numeric id: delete
  still finds and moves or clears it.
- A **group** `DEFAULTMODEL.TOOLS` binding pointing at the model: moved
  or cleared and listed in the API response with `scope: "group"` and
  the group id.
- Replacement skips a candidate whose provider has no key; the next
  usable candidate wins, or the row clears.
- A model that is not a default: delete succeeds, no config row changes.

## Files

- `backend/src/Service/Admin/AdminModelsService.php` — call a new class;
  do not grow `deleteModel` past a short delegation.
- New `backend/src/Service/Admin/DefaultModelMover.php` — the rules above.
- `backend/src/Controller/AdminModelsController.php` — response shape,
  OpenAPI.
- `frontend/src/components/config/AIModelsAdminPanel.vue` — toast from
  `moved` / `cleared`.
- The five `config.json` locale files.
- Generated `frontend/src/generated/api-schemas.ts` via
  `make -C frontend generate-schemas`.

## Gate

`make ci-local` and `make test-e2e`. Then J-OI-2 in STATUS.
