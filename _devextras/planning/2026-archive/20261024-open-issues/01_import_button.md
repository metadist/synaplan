# 01 — Import stays clickable

**Issue:** [#2678](https://github.com/metadist/synaplan/issues/2678) (bilogic).
**Commit:** `fix(models): keep the import action on screen`
**Class:** `ota-candidate` (`frontend/src/**`, `frontend/tests/**`).
**Branch:** `fix/20261024-import-button`

## Problem

AI models → an OpenAI-compatible provider → Import models opens
`ModelImportDialog`. With a long model list the dialog is taller than the
window. The list does not shrink (`min-height: auto` on the flex child),
so the Import button is drawn below the viewport. The overlay does not
scroll. The button cannot be seen and cannot be clicked.

The dialog sets `max-h-[85vh]` and does not use `modal-overlay` /
`modal-panel`, and it is not teleported, so a clipping ancestor can hide
the same button.

## Goal

The Import and Cancel buttons stay inside the window, on a short phone
and on a long list. The list scrolls. The buttons do not.

## User-flow

Pointer: AI models, admin scope, the provider row's Import models.
Journey: **J-OI-1**.

1. Sign in as `admin@synaplan.com`. Open `/ai/models`.
2. Open Import models on a provider whose list is longer than the window.
3. The Import button is visible without scrolling the page. Click it
   (or Cancel). The dialog closes.
4. Repeat with an unreachable endpoint and with an empty list: Retry and
   Cancel stay inside the dialog.
5. Dark theme, and a 320 px wide window.

Exit (UX contract §6):

1. J-OI-1 walked in the browser.
2. Imported models show up in the same catalog the admin already has open.
3. The existing applied sentence stays: how many were added, how many
   skipped. No new copy in this sprint.
4. Unreachable and empty states keep their action inside the dialog.
   There is no flag.
5. Light, dark, and 320 px. The glass theme does not restyle
   `modal-panel`; the walk checks it anyway.

## Change

`frontend/src/components/admin/plugs/ModelImportDialog.vue` only.

- Teleport to `body`.
- Overlay: `modal-overlay`. Panel: `modal-panel`, `flex flex-col min-h-0
  max-h-full overflow-hidden`.
- Header and footer: `shrink-0`. The list region: `min-h-0 flex-1
  overflow-y-auto`. The footer is not inside that region.
- Footer wraps (`flex-wrap`) so two buttons fit at 320 px.

No new string. No API change.

## Test

`frontend/tests/unit/components/admin/plugs/ModelImportDialog.spec.ts`

A preview with enough rows that the old dialog would overflow. Assert:

- the overlay has `modal-overlay`
- the panel has `modal-panel`, `min-h-0`, `overflow-hidden`
- `[data-testid="model-import-apply"]` is in the panel and not in the
  scrolling region

The test is red before the class change: the apply button sits in a
panel that has neither `modal-panel` nor `min-h-0`.

## Gate

`make ci-local` and `make test-e2e`. Then J-OI-1, recorded in STATUS.
