<!-- title: File work: a follow-up cannot use the file the previous run just made, and the failure card gives no reason -->
<!-- type: Bug -->
<!-- labels: prio:1, area:files, area:chat -->
<!-- status: shipped -->
<!-- issue-type: Bug -->

> **Shipped** in [#2380](https://github.com/metadist/synaplan/pull/2380) (`c5b6f22b5`). A follow-up mounts a named file from this chat, or the single prior file when the text is a follow-up and there is exactly one. It does not mount every earlier file, and it does not turn the shared workspace on. Do not re-implement.

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
- Verified in code: `CodeRunRunner` mounts this turn's attachments (`$context->message->getFiles()`, then `findFilesByMessageIds` only as a fallback). The planner prompt says to omit `inputFileIds` and never invent ids. `params.useWorkspace: true` mounts the user's persistent folder, which later runs can read — and which also survives into other chats. Results otherwise go to Generated via `ComputeArtefactStore` and are not mounted again.
- Do not mount every earlier file into every later run. That puts files the person did not name into a model-written script, and it can blow the file cap. Do not turn `useWorkspace` on by default: the workspace is shared across chats, not scoped to this one.
- Failure copy: `ComputeRunCard.vue` shows `card.error` or `taskPlan.failedBody` ("This step couldn't be completed."). The "Check Workspace" sentence is wrong when the run never used a workspace.

Fix direction: when the user points at a file this chat just produced, resolve that artefact to its real file id and mount it the same way as an attachment. List those names to the planner ("files this chat already saved: summary.xlsx") so it does not invent an id. A missing file becomes "The run could not find <name>. Attach it, or use the file this chat just saved." Map timeout, quota, and egress refusal to one sentence each (U8). Stderr stays behind the expandable step (issue 10), not on the card. Do not mention the Workspace unless this run used one. Inline chart preview is issue 29 — leave it there.

Journey (U10): attach CSV → summary xlsx → "chart the top ten from the spreadsheet you just made" → PNG shown inline → "Re-run with changes" → new PNG; then ask for a file that does not exist → card names the missing file.

Verification:
1. Follow-up run succeeds without re-attaching.
2. A deliberately failing script shows the first stderr line in the expanded step and a plain sentence on the card.
3. `make -C backend test` covers the artefact mount list for a chat with two prior runs.

---

## Screenshots/Logs
Card text: "File work could not finish. Check Workspace for anything this run already wrote". Workspace: 0 MB of 2048 MB.
