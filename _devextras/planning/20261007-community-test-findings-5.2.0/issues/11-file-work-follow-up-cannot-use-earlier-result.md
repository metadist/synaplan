<!-- title: File work: a follow-up cannot use the file the previous run just made, and the failure card gives no reason -->
<!-- type: Bug -->
<!-- labels: prio:1, area:files, area:chat -->
<!-- issue-type: Bug -->

## Problem
Request 1 (count a 20,000-row CSV by make and model year, save a spreadsheet) worked in 17 s and saved a two-sheet .xlsx to Library → Generated. Request 2 in the same chat ("using the summary spreadsheet you just made, chart the top ten makes as a PNG") failed after 32 s with "File work could not finish. Check Workspace for anything this run already wrote" and no reason. The Workspace was empty: the first result went to Generated, not the workspace, so the follow-up had nothing to work on.

---

## Expected
Files a run produced earlier in the same chat are available to later runs automatically (mounted read-only by name), so "the spreadsheet you just made" resolves. When a run fails, the card says why in one sentence a non-technical user understands (missing input file, script error with the first line, timeout, quota) and names the recovery.

## Actual
1. Run 1: ok, artefacts in Generated, "Re-run with changes" button appears.
2. Run 2 referencing the result: 32 s, generic failure, Workspace 0 MB of 2048 MB.
3. Card text points to the Workspace, which never received anything.

---

## Steps to reproduce
1. File work on; attach a CSV; ask for a summary spreadsheet. Wait for the xlsx.
2. In the same chat ask to chart "the summary spreadsheet you just made".
3. Observe the card and the empty Workspace.

---

## Notes
- Findings: F48 — community test round on 5.2.0.
- Verified in code: `CodeRunRunner` (`backend/src/Service/Multitask/Execution/Runner/CodeRunRunner.php`) mounts **this turn's attachments** (`findFilesByMessageIds` for the current message) and, only when the planner set `params.useWorkspace: true` on that run, the user's persistent workspace. Results are saved through `ComputeArtefactStore` into the Generated tab; a later run never sees them unless the person re-attaches them. The planner prompt tells the model to set `useWorkspace` "when the user wants to continue earlier file work" — on the first request nobody knows a follow-up is coming.
- Failure copy: `ComputeRunCard.vue` shows `card.error || $t('taskPlan.failedBody')`; the sidecar error (stderr first line, exit code, timeout) is not mapped to a sentence.

Fix direction: (1) mount the chat's earlier compute artefacts (and files attached earlier in the chat) read-only into every later run of the same chat, listed to the model by name in the prompt ("files from earlier in this chat: summary.xlsx"); (2) a `FileNotFoundError` / missing-input in the sandbox becomes "The run could not find <name>. Attach the file or ask again with the file named."; (3) map exit code / timeout / quota / egress refusal to one sentence each (U8), keep the raw stderr behind the expandable step (see the task-step detail issue); (4) stop pointing at the Workspace unless the run used one.

Journey (U10): attach CSV → summary xlsx → "chart the top ten from the spreadsheet you just made" → PNG shown inline → "Re-run with changes" → new PNG; then ask for a file that does not exist → card names the missing file.

Verification:
1. Follow-up run succeeds without re-attaching.
2. A deliberately failing script shows the first stderr line in the expanded step and a plain sentence on the card.
3. `make -C backend test` covers the artefact mount list for a chat with two prior runs.

---

## Screenshots/Logs
Card text: "File work could not finish. Check Workspace for anything this run already wrote". Workspace: 0 MB of 2048 MB.
