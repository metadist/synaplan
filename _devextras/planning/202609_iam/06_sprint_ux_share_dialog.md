# Sprint IAM-UX — Share dialog professionalization

**Track 1 (IAM), follow-up after S3.** Steps `IAM-UX1`–`IAM-UX4`.

**Goal:** The sharing *capability* is on `main`. This sprint is the
professional user-flow that S2 listed as a dialog and a chip. One-row
add, kind-specific consequence, open-resource five-question check on
every kind, after-share sentence that names where the other person
looks. Required before Agent Builder publish or custom-tool / template
share reuse `ShareDialog.vue`.
**Depends on:** S2/S3 and Incoming chats (#1717) on `main`.
**Unlocks:** track 2 S3 `AB22`, track 4 `TL34` Share, track 4 `TL41`
templates.
**Repos:** `synaplan/` only. **Class:** `ota-candidate`.
**Flag:** none new — `IAM.SHARING_ENABLED` as today.
**User-flow:** [`../202609_ux_user_flows.md`](../202609_ux_user_flows.md)
J-IAM-4 and §7. Wireframe:
[`../202609_ux_user_flows/share-dialog-v2.md`](../202609_ux_user_flows/share-dialog-v2.md).

---

## 0. Why this sprint exists

S2 shipped APIs plus a stacked form. Members could not find group-shared
chats until #1717. Later tracks will open the same dialog for
assistants, tools and templates. Reusing the S2 form would repeat the
miss at a larger scale.

---

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `frontend/src/components/iam/ShareDialog.vue`, `SubjectPicker.vue`, `PermissionSelect.vue` | The stacked form to replace, not fork |
| `frontend/src/views/IncomingChatsView.vue` | Findability pattern and copy to name in the consequence sentence |
| `frontend/src/i18n/en.json` `iam.dialog.*`, `iam.permission.*` | Keys to extend with kind-specific consequence lines |
| [`../202609_ux_user_flows.md`](../202609_ux_user_flows.md) §4.1 table | Binding copy per kind |

---

## 2. Developer steps

See UX contract §7. No new API. `IAM-UX1` is the dialog interaction;
`IAM-UX2` extends the #1717 banner/pill to assistant, saved task,
widget and (when present) custom tool; `IAM-UX3` names the recipient
path in the toast; `IAM-UX4` is the walk + tests.

---

## 3. Exit criteria

1. J-IAM-4 walked in the browser (U10): share a chat with Sales using
   the one-row pattern; consequence names Incoming chats; a Sales
   member finds it under Incoming chats *and* History → Group in ten
   seconds; opening it names owner, via, and what they may do.
2. Assistant / task / widget / folder each show their own consequence
   sentence from the §4.1 table (U3).
3. Empty, search-miss, load-failed, Everyone-hidden, flag-off, 320 px
   (wireframe states).
4. Dark + V2 + 320 px (U9). No second share modal.

---

## 4. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| `IAM-UX1` | `feat(iam): professionalize ShareDialog as one-row add with kind-specific copy` | ota-candidate | S3 on `main` |
| `IAM-UX2` | `feat(iam): answer the five-question check on every shared kind` | ota-candidate | `IAM-UX1`, #1717 |
| `IAM-UX3` | `feat(iam): name the recipient path after a successful share` | ota-candidate | `IAM-UX1` |
| `IAM-UX4` | `test(iam): walk Share dialog v2 states and locales` | ota-candidate | `IAM-UX2`, `IAM-UX3` |
