<!-- title: Routing / File work: edit requests on an attached file are refused instead of routed to File work; the sandbox has no PDF-editing library -->
<!-- type: Feature -->
<!-- labels: prio:2, area:routing, area:files -->
<!-- issue-type: Feature -->

## Summary
When a person attaches a file and asks to change it, the planner offers File work (code run on the attached file) without the person having to name it, and the sandbox image carries a library that can edit a PDF in place.

---

## Problem / Motivation
With File work on, a 13 KB PDF was attached with "change 'Dummy PDF file' to 'PDF File'". Answer after 4 steps: "I can't modify the PDF file directly here". Retrying with "Use file work to replace … and give me the edited PDF": "I can't edit or return the PDF attachment with the tools available in this chat." A CSV attached in a later test did reach File work, so attachments can get there; the refusals were for a PDF edit. The sandbox Python image has pypdf but no PDF-editing library (PyMuPDF) and runs offline, so an in-place text edit may be impossible even when routed (F26). Open WebUI with Open Terminal produced an edited PDF on the first try — but rebuilt the document (A4 → Letter, fonts and metadata lost), so an in-place editor matters on either platform.

---

## Goal
"Change X to Y in this file" on an attached PDF, DOCX or XLSX runs File work, returns the edited file as a chip in the reply, and the step shows what was done. When the sandbox genuinely cannot do it, the reply names the limit ("the sandbox cannot edit PDF text in place") instead of "the tools available in this chat".

---

## Acceptance criteria
- [ ] The planner's capability description for code run covers "modify / edit / replace in the attached file" intents; a routing characterization case exists for a PDF edit request with an attachment.
- [ ] The sandbox Python image includes an in-place PDF editor (PyMuPDF or equivalent) and openpyxl / python-docx for Office files — dependency additions are listed in the PR for the ask-first review.
- [ ] The edited file is saved to Generated and shown in the reply (chip with preview and download).
- [ ] When routing still declines, the reason is specific and names the next step (U8).
- [ ] The "1 seconds" copy is fixed via plural rules (also covered by the task-step issue).

---

## Notes
- Findings: F26 (routing half) — community test round on 5.2.0; comparison §"Head-to-head: edit text inside a PDF".
- Code: `backend/src/Service/Multitask/Execution/Runner/CodeRunRunner.php` (capability description and prompt lines), the planner prompt under Routing, `docs/COMPUTE.md` (sandbox image contents), the compute sidecar image definition.
- Journey (U10): attach a PDF → "replace 'Dummy PDF file' with 'PDF File'" → File work runs → edited PDF chip → open preview → text changed, page size and fonts unchanged → download.

---

## Screenshots/Logs
Refusals: "I can't modify the PDF file directly here"; "I can't edit or return the PDF attachment with the tools available in this chat."
