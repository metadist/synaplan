<!-- title: RAG: show citations with the matched passage for answers from knowledge files -->
<!-- type: Feature -->
<!-- labels: prio:1, area:rag, area:chat -->
<!-- issue-type: Feature -->

## Summary
Every answer that used Library files shows which file(s) and which passage(s) it drew on — a chip per source after the claim or at the end of the answer, opening the matched passage with tables intact — the way web search answers already show a Sources carousel with numbered citations.

---

## Problem / Motivation
Five questions on four files: Synaplan answered 4 of 5 correctly, but on the invoice it reversed the parties (named the seller's address as the recipient and the customer as the likely issuer). Nothing on screen let the person check — knowledge answers carry no citations or sources. Open WebUI (5 of 5) puts a file chip after each claim that opens the matched passage. For the testers this was the headline trust gap: "a wrong answer cannot be checked" (F40). Web search answers in Synaplan already do this well (numbered citations, Sources carousel) — knowledge answers should use the same pattern.

---

## Goal
After a knowledge answer, the person sees the files used, can open each matched passage (with the file name, page or line range, and the chunk text, tables preserved), and can jump to the file in the Library. The composer shows which knowledge folder is active before sending.

---

## Acceptance criteria
- [ ] The backend emits the retrieved chunks used for the answer (file id, name, folder, score, start/end line or page, chunk text) as an SSE event alongside the existing `memories_loaded` / `complete` events, and stores them with the message so they survive a reload.
- [ ] The frontend renders a Sources row for knowledge answers using the same component family as web search sources (numbered, consistent icon, `EyeIcon` to preview).
- [ ] Opening a source shows the passage with layout kept (Markdown tables render as tables; the Tika/Docling Markdown output is used when present).
- [ ] Inline numbered markers `[1]` in the answer link to the source when the model emits them; the system prompt asks for them (same convention as web search).
- [ ] Sources are visible in assistant chats, plain chats with a knowledge folder, and the widget.
- [ ] No sources ⇒ no empty row (U11).

---

## Notes
- Findings: F40 — community test round on 5.2.0.
- Where retrieval happens: `backend/src/Service/Message/Handler/ChatHandler.php` (RAG scopes, `agentRagScopes()`), `backend/src/Service/File/VectorizationService.php` (chunks carry `start_line` / `end_line` from `TextChunker`). The web search Sources implementation is the pattern to mirror for the event shape and the UI (see `WebSearchRunner` and the chat Sources carousel).
- Party-reversal cause: extraction flattens layout so "Attention to" loses its value. Keeping the Markdown table output from Tika/Docling in the chunk text helps both the model and the passage view; the separate web page reader issue asks for the same extraction path.
- Journey (U10): upload invoice PDF → ask "who is the customer?" → answer shows a source chip → click → passage shows the address block as a table → "Open in Library" → file row. Also walk it in the widget.

---

## Screenshots/Logs
—
