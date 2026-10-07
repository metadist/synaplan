<!-- title: File work: results are not shown inline (chart, table), "Preview" downloads, data is printed twice, and generated files carry long internal IDs -->
<!-- type: Bug -->
<!-- labels: prio:2, area:files, area:chat -->
<!-- issue-type: Bug -->

## Progress
Generated names use the file's own name (`chart.png`), not the run id. An image result is shown inline, with preview and a separate download. Tables, repeated stdout, the "image request" label, and Library time zones are still open.

## Problem
The first File work test worked end to end (CSV → totals, PNG bar chart, saved to Library → Generated, about 30 s). Rough edges: the chart is not shown inline in the reply; the in-chat "Preview" link downloads one of the files; the data is printed twice as plain text; file names carry long internal IDs; the planner first labelled the request "Looks like an image request"; Library shows times in UTC while the chat shows local time.

---

## Expected
Images produced by a run are shown inline in the reply with preview and download; tabular results appear once, as a table; Preview opens a preview; generated files get friendly names (`ev-summary-by-make.xlsx`, not `run_8f3a…_out_1.xlsx`); times are local everywhere.

## Actual
1. PNG chart: a chip, not an image.
2. "Preview" → download.
3. Data printed twice as plain text (reproduced again in the follow-up test).
4. `…<long id>…` file names (reproduced again).
5. Planner label: "Looks like an image request" for a CSV summary request.

---

## Steps to reproduce
1. File work on; attach a small sales CSV; ask for totals per region and a bar chart.
2. Read the reply; click Preview; open Library → Generated.

---

## Notes
- Findings: F25 (and the repeated points in F48) — community test round on 5.2.0.
- Code: `frontend/src/components/multitask/ComputeRunCard.vue`, `backend/src/Service/Compute/ComputeArtefactStore.php` (naming, Generated tab chips), `CodeRunRunner` result rendering, `ComposeReplyRunner` (why the data is in the prose twice: stdout plus the model's restatement).
- The preview surface is the shared `FilePreview` from the chat file-chip issue — build that once and use it here.

Fix direction: artefacts of type image render inline (thumbnail → preview), tables from CSV / XLSX artefacts render as a table block (first N rows); stdout is shown once in a collapsible "Output" block and the prose says what the files contain instead of repeating the data; names: `<slug of request>-<n>.<ext>` with the internal id kept in metadata; the "image request" pre-label comes from the fast classifier — add a characterization case; Library time formatting uses the profile time zone like chat.

Journey (U10): CSV → chart → image inline → click → preview → Download → Library → Generated → friendly name, local time.

Verification:
1. Reply shows the PNG inline and the data once.
2. Preview opens the preview; Download downloads.
3. Characterization snapshot reviewed if the fast path label changed.

---

## Screenshots/Logs
Planner label (5.2.0): "Looks like an image request".
