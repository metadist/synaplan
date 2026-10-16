<!-- title: Library: a new folder is only "stored locally" until a file lands in it, and the bulk bar has no Move to folder -->
<!-- type: Feature -->
<!-- labels: prio:2, area:files -->
<!-- issue-type: Feature -->

## Summary
Creating a folder creates it on the server immediately (so it can be shared and picked by an assistant right away), and selected files can be moved to a folder from the bulk bar.

---

## Problem / Motivation
Setting up a knowledge folder for the five-question test had friction: a new folder is only "stored locally" until a file lands in it, so it is not shareable or pickable yet; there is no bulk move (the bulk bar offers only Combine as PDF and Delete), so each of the four files had to be moved one by one (F40 setup friction).

---

## Goal
Create a folder, share it, point an assistant at it, then fill it — in any order; move many files at once.

---

## Acceptance criteria
- [ ] New folder → one request creates the folder resource on the server; the folder card shows Share and appears in the assistant folder picker with zero files.
- [ ] Bulk bar: "Move to folder" (`FolderArrowDownIcon` or the icon the conventions map), opens a folder picker with "New folder…"; the result sentence says how many moved and where (U8).
- [ ] Moving re-scopes the files' index entries so searches in the target folder find them without re-indexing.
- [ ] Empty folder state: one sentence plus Upload / Move here (U5).
- [ ] Five locales; dark theme; 320 px bulk bar.

---

## Notes
- Findings: F40 (setup friction) — community test round on 5.2.0.
- Code: Library folder model — today a folder is implied by `BGROUPKEY` on files (`backend/src/Controller/FileController.php` group handling), which is why an empty folder has nowhere to live server-side; the knowledge-folder IAM resource (`KnowledgeFolderKind`) keys on `{ownerId}:{groupKey}`. Decide in the PR whether an explicit folder table is needed (migration — ask-first) or whether a zero-file folder can be represented in the existing group metadata.
- Journey (U10): New folder "Invoices 2026" → Share with a colleague → assistant picks it → bulk-select four files → Move to folder → "4 files moved to Invoices 2026" → colleague searches → finds them.

---

## Screenshots/Logs
—
