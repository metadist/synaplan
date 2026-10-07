<!-- title: Files: allowed extensions, maximum size and maximum files per message are constants in code; admins cannot change them and JSON, XML, HTML, archives, code and log files are excluded -->
<!-- type: Feature -->
<!-- labels: prio:2, area:files, area:admin -->
<!-- issue-type: Feature -->

## Summary
An admin setting for allowed file extensions, maximum file size and maximum files per message, with a broader default list that includes JSON, XML, HTML, ZIP / TAR / GZ, plain-text code and log files.

---

## Problem / Motivation
Upload types are a fixed list in code (`FileStorageService::ALLOWED_EXTENSIONS`) with a fixed 128 MB limit; admins cannot change either. The list excludes JSON, XML, HTML, ZIP, TAR, GZ, code and log files — exactly what a developer or an operations team wants to drop into a chat. In Open WebUI the admin decides the list, the maximum size and the maximum count (F27).

---

## Goal
An operator decides what their people may upload, within a hard server ceiling, and the UI tells users the real list.

---

## Acceptance criteria
- [ ] System configuration → Files: allowed extensions (chips, with "Reset to default"), maximum file size (MB, capped by the server ceiling that PHP / proxy limits allow — shown next to the field), maximum files per message.
- [ ] Defaults extend the current list with `json xml html htm zip tar gz tgz log txt md csv` and common code extensions (`py js ts php go rs java c h cpp sh yaml yml toml ini`); archives are stored and listed, not extracted, unless the extractor supports them.
- [ ] Group policy override "upload files" on / off (pairs with the group-policies issue).
- [ ] The upload hint, the chat composer and the Library read the effective list from runtime config; the backend validates with the same values.
- [ ] A rejected file says which rule it broke and the limit (U8).

---

## Notes
- Findings: F27 — community test round on 5.2.0.
- Verified in code: `backend/src/Service/File/FileStorageService.php` — `MAX_FILE_SIZE = 128 * 1024 * 1024`, `ALLOWED_EXTENSIONS = […]` constants; the size check at ~line 244.
- Pattern: database-backed settings in `SystemConfigService` (`backend/src/Service/Admin/SystemConfigService.php`), exposed through `/api/v1/config/runtime`; BCONFIG defaults are bootstrap-only, so a migration is needed if existing installs should get the broader list.
- Journey (U10): admin adds `json` → user drops a `.json` → accepted → admin lowers max size to 10 MB → a 20 MB file is rejected with the limit named.

---

## Screenshots/Logs
—
