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
- Findings: F47 — community test round on 5.2.0. Companion of the large-upload OOM issue (F46); independent fix.
- Look at the Library upload queue component and its XHR/fetch call: the X is bound to "remove from selection" and is disabled once the request started. Cancel needs an `AbortController` per file; on abort call the delete endpoint for the partially stored row if the server already created one.
- The "lost files" banner should be driven by the server list (which rows exist with status ≠ error) rather than by the in-memory selection that a reload empties.

Fix direction: per-file `AbortController`; X becomes "Cancel upload" with an `aria-label` and a tooltip while transferring; on abort remove the partial file (server delete) and the row; after reload reconcile the selection against the Library before warning; consequence sentence on cancel ("The upload stops and the partial file is removed.", U3).

Journey (U10): start a 100 MB upload → click Cancel at 30 % → row gone, Library has no row → start again → reload at 50 % → banner names only the unfinished file; finished files are not re-offered.

Verification:
1. Cancel leaves no orphan row (Library list and storage meter unchanged).
2. Reload-with-finished-upload shows no "lost" warning for that file.
3. Keyboard: Cancel reachable with Tab, Escape does not silently abort.

---

## Screenshots/Logs
Banner: "1 selected file(s) were lost … Please select the files again".
