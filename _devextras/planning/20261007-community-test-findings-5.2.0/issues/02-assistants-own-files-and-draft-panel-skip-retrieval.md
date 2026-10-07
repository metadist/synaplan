<!-- title: Assistants: "Also search the person's own files" and Try-a-draft run no file search; raw <think> blocks are shown -->
<!-- type: Bug -->
<!-- labels: prio:1, area:rag, area:chat -->
<!-- issue-type: Bug -->

## Problem
With "Also search the person's own files" switched on and saved, a chat started from the assistant answers "I don't see an invoice here" in 3 s and 625 tokens, so no retrieval ran. The Try-a-draft panel ignores knowledge too and prints the model's raw `<think>` reasoning as answer text.

---

## Expected
When the assistant opts into the person's own files, a knowledge question runs the same retrieval as a plain chat with a knowledge folder and answers from the files. The draft panel uses the same path as a real chat. Reasoning blocks are never shown as answer text.

## Actual
1. Assistant with instructions "answer only from the four test files", web lookup off, own-files on, saved.
2. Start chat → ask about the invoice → "I don't see an invoice here", 3 s, 625 tokens.
3. The same question in a plain chat with the knowledge folder picked under + answers correctly (F40).
4. Try-a-draft: same miss, plus `<think>…</think>` text printed verbatim.

---

## Steps to reproduce
1. Upload four PDFs to the Library (default folder).
2. Create an assistant, Knowledge → "Also search the person's own files" on → Save.
3. Start chat → ask a question only the files answer.
4. Open Try-a-draft on the same assistant and ask again.

---

## Notes
- Findings: F44 (3) and (4) — community test round on 5.2.0.
- Verified in code: the scope is built — `ChatHandler::agentRagScopes()` (`backend/src/Service/Message/Handler/ChatHandler.php`) appends `new RagScope($viewerId, null)` when `RuntimeProfile::$includeUserFiles` is true (`backend/src/Service/Agent/AgentRuntimeResolver.php` line ~161). Whether retrieval runs at all for the turn is decided upstream (classifier / fast path / `defer_routing_to_chat`); a 3 s, 625-token answer means it did not.
- Candidate causes to test, in order: (a) the sorter never selects a knowledge path for assistant turns, (b) `RagScope($viewerId, null)` does not match files that sit in named folders, (c) the draft panel calls a different handler that skips RAG entirely.
- `<think>` stripping exists for the normal chat path; the draft panel bypasses it.

Fix direction: route the draft panel through the same message handler as a real chat (one code path, one retrieval); make retrieval for assistant turns independent of the generic sorter when the definition declares knowledge (own folder, shared folders or own files) — the assistant said it has files, so search them; strip reasoning blocks in one place used by both surfaces. Add a characterization test: assistant with `includeUserFiles` + a user file ⇒ a RAG search is issued.

Journey (U10): create assistant → own-files on → Save → Start chat → ask → the answer names the file; then Try-a-draft → same answer, no `<think>`.

Verification:
1. Message details for the assistant turn show a retrieval step and the matched file.
2. Draft panel answer equals the chat answer in substance and shows no reasoning markup.
3. Routing characterization snapshots re-recorded and reviewed if the sorter contract changed.

---

## Screenshots/Logs
Answer text: "I don't see an invoice here" (3 s, 625 tokens) while the same files answer in plain chat.
