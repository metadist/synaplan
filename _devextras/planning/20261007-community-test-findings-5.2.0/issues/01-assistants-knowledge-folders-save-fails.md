<!-- title: Assistants: saving with a shared folder fails with "knowledge.folders entry does not match the expected shape" -->
<!-- type: Bug -->
<!-- labels: prio:1, area:rag -->
<!-- issue-type: Bug -->

## Problem
Adding a Library folder to an assistant's knowledge and saving fails with "This part could not be saved. knowledge.folders entry does not match the expected shape"; the builder keeps saying "Unsaved changes" until the folder is removed. The assistant cannot be grounded in Library files at all.

---

## Expected
Any folder the picker offers (own or shared with "Can use" or higher) can be added and saved. If a folder id is genuinely invalid, the message names the folder and what is wrong with it.

## Actual
1. Pick a Library folder under Knowledge → Shared folders → Save.
2. Toast: "This part could not be saved. knowledge.folders entry does not match the expected shape".
3. The page stays dirty ("Unsaved changes") until the folder is removed again.

---

## Steps to reproduce
1. In the Library, create a folder whose name contains a space (for example `Test KB files`) and upload one file.
2. Assistants → Create assistant → Knowledge → add that folder → Save.
3. Observe the error. Repeat with a folder named `testkb` — it saves.

---

## Notes
- Findings: F44 (1) — community test round on 5.2.0 (2026-10-05/06).
- Root cause candidate (verified in code): `AgentDefinitionValidator::FOLDER_PATTERN = '/^\d+:[A-Za-z0-9:_.@+-]+$/'` (`backend/src/Service/Agent/Definition/AgentDefinitionValidator.php`). The frontend emits `${ownerId}:${group.name}` (`frontend/src/components/assistants/BuilderKnowledge.vue`, `loadFolderOptions`). Any folder name with a space, umlaut or other character outside that class is rejected with exactly this message (`stringList()` → "entry does not match the expected shape").
- `KnowledgeFolderKind::parseId()` (`backend/src/Service/Iam/ResourceKind/KnowledgeFolderKind.php`) accepts any non-empty group key, so the validator is stricter than the id format it validates.
- Shared folder ids come from `iamApi.listSharedWithMe('knowledge_folder')` and may carry the same characters.

Fix direction: accept the folder ids the picker already emits. `BFILES.BGROUPKEY` is `varchar(128)`; folder names people type contain spaces and non-ASCII letters. Allow those, and reject empty keys, control characters (including newlines), and keys longer than 128. Do not replace the pattern with `^\d+:.+$` — `.` does not match a newline, but it does accept control characters and oversized keys that cannot be stored as a group. `KnowledgeFolderKind::parseId()` stays the splitter (first colon only). Existing saved ids that match today's pattern must still load. The error names the folder (`knowledge.folders[1]`) instead of only the path.

Journey (U10): create assistant → add a shared folder with a space in its name → Save → ask a question answered only by a file in that folder → the answer uses the file.

Verification:
1. Folder `Test KB files` and folder `Rechnungen 2026 — Müller` both save.
2. `make -C backend test` includes a validator test for both names.
3. A chat started from the assistant answers from a file in that folder.

---

## Screenshots/Logs
Toast text: "This part could not be saved. knowledge.folders entry does not match the expected shape".
