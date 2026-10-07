<!-- title: Chat: open a preview from every file chip, with separate Download and Re-attach actions; render Office files as pages and show extracted text formatted -->
<!-- type: Feature -->
<!-- labels: prio:2, area:chat, area:files -->
<!-- issue-type: Feature -->

## Summary
Every file chip in chat (composer, sent message, "Files in this chat") opens a preview; Download and Re-attach are explicit actions on the preview, not the chip's click.

---

## Problem / Motivation
There is no preview anywhere in chat: the chip in the composer does nothing, the chip on a sent message silently starts a download, and the file in "Files in this chat" re-attaches it to the next message. The only preview is in the Library — counts, Copy Text, and the extracted text as unformatted monospace. Open WebUI shows formatted content and renders the real page of a Word file with zoom (F32). File work's in-chat "Preview" link downloads one of the files (F25).

---

## Goal
Click a file anywhere in chat and see it; download or re-attach it on purpose.

---

## Acceptance criteria
- [ ] One `FilePreview` surface (lazy-loaded modal / side panel) used by chat chips, File work result cards and the Library row's `EyeIcon`.
- [ ] Renders: images, PDF (pages), text / CSV (table for CSV), Markdown (formatted), Office (DOCX / XLSX / PPTX) as pages via the configured office renderer when present, otherwise the extracted text formatted — never raw monospace as the only view.
- [ ] Actions in the preview: Download (`ArrowDownTrayIcon`), Re-attach to the composer, Open in Library; file name, size, folder, "searchable" state and who shared it (U7).
- [ ] A chip click never downloads or re-attaches by itself.
- [ ] Keyboard: Escape closes; focus returns to the chip.

---

## Notes
- Findings: F32, F25 (Preview link) — community test round on 5.2.0.
- Frontend: chip components around `ChatInput.vue` and the message file list; the Library preview; `FileRowActions.vue` is the shared action row (icon map in `docs/FRONTEND_CONVENTIONS.md`). Backend: a page-render endpoint for Office files may exist through the Collabora / OpenCloud track — check before adding one.
- Journey (U10): attach a DOCX → click the chip → preview shows pages → Re-attach → chip in composer → send → click the chip on the sent message → preview, not download → Download.

---

## Screenshots/Logs
—
