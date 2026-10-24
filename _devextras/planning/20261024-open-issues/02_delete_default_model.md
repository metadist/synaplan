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
5. Search (the embedding default) is the clear case, never a silent
   switch. See the rule below.

Exit (UX contract §6):

1. J-OI-2 walked.
2. The new default is the selected model on the same AI models page.
3. Kind-specific sentence, five locales, written below. It says what
   moved and, when a job was cleared, that nothing was assigned.
4. Delete of a missing id stays "not found". A model that is not a
   default still deletes with the existing "deleted" sentence. No flag.
5. The toast uses the existing notification. Check light and dark.

## Rules

- Read every `DEFAULTMODEL` row whose value is this id, including other
  people's overrides (`BOWNERID ≠ 0`).
- For each row except `VECTORIZE`, pick a replacement: active,
  selectable, not retired, not the deleted id, whose
  `PickerCapabilities::forTag` includes that row's setting. Same service
  first, then any other service. Skip a model the health table marks
  offline. For a person's row (`BOWNERID ≠ 0`), skip a model that
  person's group policy forbids; if none remain, clear that row.
- `VECTORIZE` is never reassigned here. Switching the embedding model
  rebuilds the search index, and that decision already has its own
  screen (`ModelAlternatives::defaultCapability` says the same). Clear
  the row.
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
  "moved": [{ "setting": "CHAT", "ownerId": 0, "modelId": 12, "modelName": "…" }],
  "cleared": [{ "setting": "VECTORIZE", "ownerId": 0 }]
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
  the vectorize row is gone, the other embedding model was not written.
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
