<!-- title: Custom tools: the editor has no input-arguments section, no credential field and no hint for the {{input.*}} / {{response.*}} / {{credential.header}} template syntax -->
<!-- type: Feature -->
<!-- labels: prio:2, area:admin -->
<!-- issue-type: Feature -->

## Summary
The custom HTTP tool editor gets an Arguments section (name, type, description, required — what the model may send), a Credential picker (which stored secret fills `{{credential.header}}`), and inline hints for the template syntax next to the URL, headers and body fields.

---

## Problem / Motivation
Building a test tool showed gaps that apply to any custom HTTP tool: (1) the form has no section for input arguments — the test tool only got its URL argument from an imported OpenAPI document, and nothing on screen shows which arguments the model sent; (2) the template syntax (`{{input.*}}`, `{{response.*}}`, `{{credential.header}}`) is not shown on the form — the testers learned it from an error; (3) there is no field to enter a credential although `{{credential.header}}` exists, so tools for APIs that need a key cannot be built from the UI (F43 1–3).

---

## Goal
An admin builds a tool for an authenticated API from the UI alone: declares the arguments the model can fill, picks the credential, sees how to reference both in the request template, and tests it.

---

## Acceptance criteria
- [ ] Arguments section: add / remove rows with name, type (string, number, boolean, enum), description shown to the model, required flag; the resulting JSON schema is what the model receives.
- [ ] Credential field: pick an existing stored credential or create one inline (name, header name, secret — stored encrypted, never echoed back); the form shows `{{credential.header}}` as the way to use it.
- [ ] Template hints: a one-line hint under URL, headers and body ("Use {{input.city}} for an argument, {{credential.header}} for the key") plus a "Syntax" pop-over with all placeholders and an example.
- [ ] Validation on save names the exact placeholder that is unknown ("{{input.cityy}} is not a declared argument").
- [ ] Form controls follow the house chain (`px-3 py-2 rounded-xl surface-card border … txt-primary text-sm`); five locales; dark theme.

---

## Notes
- Findings: F43 (1)–(3) — community test round on 5.2.0.
- Verified in code: `frontend/src/components/config/CustomToolEditor.vue` has name, description, sideEffect radios and the request template fields — no arguments, no credential. `backend/src/Entity/CustomTool.php` already has `credentialId` (`BCREDENTIALID`), and `customToolsApi.ts` passes `credentialId` on OpenAPI import, so the backend half mostly exists; the editor never exposes it.
- Backend: `backend/src/Service/Tool/Custom/CustomToolService.php` (template validation), the credential store used by connected apps.
- Journey (U10): New tool → add argument `city` → pick credential → URL `https://api.example.com/weather?q={{input.city}}` → Save → Try it with `{"city":"Berlin"}` → status 200 and body (needs the companion "show request / response" issue) → ask in chat → tool runs.

---

## Screenshots/Logs
—
