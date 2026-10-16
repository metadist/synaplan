<!-- title: Assistants: "Add a file" should pick from the Library, not only upload from the computer -->
<!-- type: Feature -->
<!-- labels: prio:1, area:rag, area:files -->
<!-- status: shipped -->
<!-- issue-type: Feature -->

> **Shipped** in [#2379](https://github.com/metadist/synaplan/pull/2379) (`c97e79144`). Do not re-implement. The sections below describe the 5.2.0 bug.

## Summary
Let the assistant builder's Knowledge section pick files that already exist in the Library (own or shared with "Can use" or higher), in addition to uploading from the computer.

---

## Problem / Motivation
"Add a file" opens the computer's file picker only. Files that are already uploaded, indexed and possibly shared have to be downloaded and uploaded again into the assistant's own folder — a second copy, a second index, and no link to the original. Together with the folder save bug this meant an assistant could not be grounded in Library files at all in the test round (F44).

---

## Goal
From Knowledge → Files, a person opens the same Library picker that the chat composer already uses (search, type filter, status filter, thumbnails — the testers praised it), selects one or more files, and the assistant searches them without copying them.

---

## Acceptance criteria
- [ ] "Add a file" offers two actions: "From the Library" and "Upload from computer" (same wording in all five locales).
- [ ] "From the Library" opens the existing chat file picker component with the same filters; only indexed ("searchable") files are selectable, others show why not.
- [ ] A selected Library file is referenced, not copied: deleting the assistant leaves the file; the file row in the Library shows "Used by assistant <name>" (U7 — who else uses this).
- [ ] The assistant's retrieval includes the picked files (same scope mechanism as folders).
- [ ] Removing a file from the assistant is one click on the row with the consequence stated (U3).
- [ ] Empty state: one sentence plus the two actions (U5).

---

## Notes
- Findings: F44 (2) — community test round on 5.2.0.
- Today: `frontend/src/components/assistants/BuilderKnowledge.vue` `onUpload` posts a browser `File` to `promptsApi.uploadPromptFile(topic, file)`; files live in the assistant's own folder `TASKPROMPT:agent:{slug}` (`AgentKnowledgeFolders::ownFolder`).
- Picker to reuse: the chat composer's Library picker (search, type and status filters, thumbnails).
- Scope model: `AgentKnowledgeFolders::scopes()` is folder-scoped (`{ownerId, groupKey}`). A per-file reference is a new scope kind. Do not implement "reference" by copying the file into `TASKPROMPT:agent:{slug}` — that is the duplicate the testers were trying to avoid, and it drifts when the Library original changes. If a per-file scope cannot be added without a schema change, stop and say so (schema changes are ask-first).
- Journey (U10): create assistant → Add a file → From the Library → pick two indexed PDFs → Save → Start chat → ask → answer cites one of them → remove one file → ask again → it is no longer used.

---

## Screenshots/Logs
—
