<!-- title: Routing / File work: edit requests on an attached file are refused instead of routed to File work; the sandbox has no PDF-editing library -->
<!-- type: Feature -->
<!-- labels: prio:2, area:routing, area:files -->
<!-- issue-type: Feature -->

## Summary
When a person attaches a file and asks to change it, the planner offers File work (code run on the attached file) without the person having to name it. A PDF-editing library in the sandbox image is a follow-up, only after routing is proven, and only with an in-place edit — not a rebuilt page.

---

## Problem / Motivation
With File work on, a 13 KB PDF was attached with "change 'Dummy PDF file' to 'PDF File'". Answer after 4 steps: "I can't modify the PDF file directly here". Retrying with "Use file work to replace … and give me the edited PDF": "I can't edit or return the PDF attachment with the tools available in this chat." A CSV attached in a later test did reach File work, so attachments can get there; the refusals were for a PDF edit. The sandbox Python image has pypdf but no PDF-editing library (PyMuPDF) and runs offline, so an in-place text edit may be impossible even when routed (F26). Open WebUI with Open Terminal produced an edited PDF on the first try — but rebuilt the document (A4 → Letter, fonts and metadata lost), so an in-place editor matters on either platform.

---

## Goal
"Change X to Y in this attached PDF" is offered to File work. If the sandbox cannot edit PDF text in place, the reply says that, not "the tools available in this chat". DOCX and XLSX are out of this issue.

---

## Acceptance criteria
- [ ] The planner's capability description for code run covers "modify / edit / replace in the attached file" intents; a routing characterization case exists for a PDF edit request with an attachment.
- [ ] A PDF-editing library in the sandbox image (PyMuPDF or equivalent) is a separate change and needs the dependency ask first. Do not start it until an attached PDF is actually routed to File work. Success is an in-place edit: same page size and an embedded font still present. A rebuilt one-page file (the Open WebUI result in the comparison was 13 KB → 589 bytes, A4 → Letter) is not success. Office libraries are out of this issue.
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
