<!-- title: IAM: group policies cover model defaults and 13 agent/tool features but no everyday chat actions (upload, edit, delete, export, share, temporary chat) -->
<!-- type: Feature -->
<!-- labels: prio:3, area:admin -->
<!-- issue-type: Feature -->

## Summary
Extend the Policies tab with everyday chat and sharing permissions — upload files, read web pages by URL, edit and rerun, delete chats, export chats, share chats, share folders, use incognito / temporary chat, web search, image generation — each inherit / on / off per group with the instance default shown and an instance-wide lock, exactly like the existing 13 feature policies.

---

## Problem / Motivation
Synaplan controls how much a user may consume (levels) and 13 feature toggles mostly for agents and tools; it does not control what a user may do in a plain chat. Open WebUI's default-permissions dialog has 48 switches, 21 of them for chat actions and 7 for sharing (F42, comparison §"Levels, groups and admin controls"). Companies that want to switch off uploads or chat export for a group have no handle.

---

## Goal
An admin can say "this group may not upload files or export chats" and the UI for those members shows no such control (U11), with the instance default visible next to each choice.

---

## Acceptance criteria
- [ ] New policy keys in the existing policy framework: `chat.upload`, `chat.read_url`, `chat.edit_rerun`, `chat.delete`, `chat.export`, `chat.share`, `folders.share`, `chat.temporary`, `chat.web_search`, `chat.image_generation`; each with instance default, inherit / on / off per group, lock.
- [ ] Frontend reads the effective policy from runtime config and hides the corresponding controls; the backend enforces the same (403 with a one-sentence reason).
- [ ] Policies tab groups them under "Chat" and "Sharing" headings; each row shows the instance default (existing pattern).
- [ ] A default-group-for-new-users setting if it does not exist yet (comparison notes `DEFAULT_GROUP_ID` in Open WebUI; "not found" in Synaplan).
- [ ] Five locales; the widget respects the widget owner's policies.

---

## Notes
- Findings: F42 — community test round on 5.2.0. **Effort L** — sprint file with the named journey before coding.
- Code: the policy framework behind People → Policies (feature policy entity / service in `backend/src/Service/Iam/`), runtime config exposure, the chat UI controls.
- Journey (U10): admin turns `chat.export` off for group Sales → a Sales member's chat menu has no Export → admin locks `chat.upload` off instance-wide → every composer loses the attach button; the policy row says "Locked by the instance".

---

## Screenshots/Logs
—
