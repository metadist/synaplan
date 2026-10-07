<!-- title: Files: an upload in progress cannot be cancelled, and a reload warns about files that did upload -->
<!-- type: Bug -->
<!-- labels: prio:1, area:files -->
<!-- issue-type: Bug -->

## Problem
While a Library upload runs, the red X next to the file shows a not-allowed cursor and does nothing, and there is no Cancel button; the only way out is to reload or close the page. After a reload during an upload the page shows "1 selected file(s) were lost … Please select the files again" although the file had been uploaded and is in the Library, which invites a duplicate upload.

---

## Expected
The X (or a Cancel button) aborts the transfer, removes any partial server-side file, and the row disappears. After a reload, the page only warns about files that were not uploaded; files that did land are shown as done.

## Actual
1. Start a large upload → X is disabled (not-allowed cursor), no Cancel anywhere.
2. Reload during the transfer → "1 selected file(s) were lost … Please select the files again" while the Library already lists the file.

---

## Steps to reproduce
1. Library → Upload → pick a 50 MB+ file.
2. Hover the red X while the bar moves; try to click it.
3. Reload the page mid-transfer; read the banner; check the Library list.

---

## Notes
- Findings: F47 — community test round on 5.2.0. Companion of the large-upload issue (F46); the button can land first.
- The red X is `FilesView.vue`: the remove button is `:disabled="isUploading"` with `disabled:cursor-not-allowed`. `uploadFiles()` calls `filesService.uploadFiles` with **no** `signal`. The file picker (`FileSelectionModal.vue`) already has an `AbortController` and handles `AbortError` — do not rebuild that.
- Aborting the browser request does not stop PHP. With `process_level=vectorize` the process keeps chunking after the client gives up, and the row can still appear. Cancel has to be visible on the server (disconnect or a cancel flag) or the UI must only claim "stopped" once the row is gone or marked failed.

Fix direction: pass a signal from the Library page into `filesService` (the picker already does). While the transfer runs, the X is "Cancel upload" (`aria-label`, not disabled). On cancel, delete a row this request already created; do not delete a different file that happens to share the name. After reload, compare the selection with the Library list before showing "files were lost". Consequence sentence: "The upload stops. A file that was only partly stored is removed." (U3).

Journey (U10): start a 100 MB upload → click Cancel at 30 % → row gone, Library has no row → start again → reload at 50 % → banner names only the unfinished file; finished files are not re-offered.

Verification:
1. Cancel leaves no orphan row (Library list and storage meter unchanged).
2. Reload-with-finished-upload shows no "lost" warning for that file.
3. Keyboard: Cancel reachable with Tab, Escape does not silently abort.

---

## Screenshots/Logs
Banner: "1 selected file(s) were lost … Please select the files again".
