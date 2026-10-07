<!-- title: Library: explain when a file is indexed, add a status legend, local times, sortable columns and a per-file index details panel with re-index -->
<!-- type: Feature -->
<!-- labels: prio:3, area:files -->
<!-- issue-type: Feature -->

## Summary
Library list polish in one pass: the "searchable" state is explained, times are local, the list has sortable columns for folder, type, status and sharing, and each file has an index details panel with a Re-index action.

---

## Problem / Motivation
A PDF attached in chat stays not searchable while the same file uploaded to the Library is indexed at once, with nothing explaining the difference; the searchable check and the empty circle have no legend; the accepted-types hint lists fewer types than are allowed; Uploaded times are UTC; generated files keep long internal IDs; the list has no columns for folder, type, status or sharing and no sort; indexing details are not shown per file (F30).

---

## Goal
A person understands at a glance which files the AI can search, why one is not, and can fix it from the row.

---

## Acceptance criteria
- [ ] Status column with three states and a legend / tooltips: Searchable, Not indexed (reason: attached in chat only — "Index now" action), Indexing failed (reason sentence — "Retry").
- [ ] Either chat-attached files are indexed like Library uploads (preferred, same async path as the large-upload issue) or the difference is stated on the chip and in the Library.
- [ ] Columns: name, folder, type, size, status, shared with, uploaded (local time, profile time zone); sortable; column set persists per user.
- [ ] Row → "Index details": extractor used, chunk count, embedding model, indexed at, errors; Re-index.
- [ ] The accepted-types hint is generated from `FileStorageService::ALLOWED_EXTENSIONS` (pairs with the admin-configurable types issue).
- [ ] Generated files show friendly names (pairs with the File work results issue).

---

## Notes
- Findings: F30 — community test round on 5.2.0.
- Code: Library view and row components (`FileRowActions.vue` for actions), `backend/src/Service/File/FileUploadService.php` (`processFile` for re-index), `File` entity status values (`extracting`, `vectorizing`, `vectorized`, `error`).
- Journey (U10): attach a PDF in chat → Library shows "Not indexed: attached in chat" → Index now → Searchable → Index details show 42 chunks → sort by status.

---

## Screenshots/Logs
—
