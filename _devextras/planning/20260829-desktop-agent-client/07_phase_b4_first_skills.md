# Sprint B4 — First real skills (pptx, not Outlook COM)

**Phase B (`synaplan-desktop`), sprint 4 of 5.** Steps `DC15`–`DC18`.

**Goal:** A user can produce a `.pptx` on Windows, macOS, and Linux using
the official Agent Skill, through Synaplan Desktop only. Outlook stays on
the Synaplan server path we already have.
**Depends on:** Sprints B2–B3. Checklist rows 11, 12, 15.
**Unlocks:** Sprint B5 (worth polling once a real skill exists) and the GA
flag flip in master plan §11.
**Repos:** `synaplan-desktop` (bundle). `synaplan/` docs only.

---

## 0. Why this sprint exists

PowerPoint and Outlook were the motivating examples. They are **different
problems**. Shipping a COM Outlook skill would fail Linux and fight
Synamail / M365. Shipping `pptx` proves the runtime on all three OSes
without Microsoft Office.

---

## 1. PowerPoint — bundled official skill

### 1.1 Vendor, do not live-fetch

- Source: official Anthropic `pptx` skill (Apache-2.0), reviewed commit.
- Copy into `synaplan-desktop/skills/bundled/pptx/` in the same PR as
  `NOTICE` / license text.
- Record the upstream URL + SHA in `docs/BUNDLED_SKILLS.md`.
- Do not rewrite the SKILL.md into a Synaplan prompt-pack.

### 1.2 Runtime dependencies (honest)

The skill expects some of:

- Python 3 + `markitdown[pptx]`, Pillow
- Node + `pptxgenjs` (create-from-scratch path)
- LibreOffice (`soffice`) for PDF/thumbnail (optional)

v1 client:

1. **Detect** python3, node, soffice on PATH (or configured paths).
2. Skills page shows a **readiness** line: “Python: found / missing”.
3. Missing required binary: refuse that skill with install hints (four
   locales), do not start a doomed tool loop.
4. Optional: a `make doctor` / in-app “Check this computer”.

Do **not** silently `pip install` from the model. A later step may offer
“Install Python packages for this skill” with an explicit button and a
requirements pin. Out of the first pptx PR if it bloats.

### 1.3 Binary allowlist (tighten Sprint B2 Bash)

Allow only:

- `python` / `python3`
- `node` / `npm` (npm only if we accept create-from-scratch; otherwise
  skip npm in v1 and document “create via python-pptx path”)
- `soffice` / `soffice.bin`
- the skill’s own `scripts/*.py`

Anything else (curl to random hosts, powershell, `cmd`) → deny unless a
future skill is explicitly allowlisted. Network from scripts: default
**off**; pptx does not need the internet.

### 1.4 Acceptance utterance

User in Synaplan Desktop:

> Create a three-slide presentation about Synaplan Desktop. Save it in
> my Synaplan out folder.

Expect: `~/Synaplan/out/.../*.pptx` exists and opens in
LibreOffice / PowerPoint / Keynote. Optional “Upload to Synaplan
Sources” button (uses `desktop:files` + existing upload API).

### 1.5 Manual matrix (PR evidence)

| OS | Python | Node | soffice | Result |
| -- | ------ | ---- | ------- | ------ |
| Linux CI runner (headless) | yes | optional | optional | at least “create pptx via python” fixture |
| Linux desktop | | | | screenshot + file |
| macOS | | | | screenshot + file |
| Windows | | | | screenshot + file |

CI must run a **hermetic** fixture: a stub `SKILL.md` that writes a
minimal pptx via a vendored tiny script **without** LibreOffice, so Linux
PR CI stays green. Full official skill = manual + nightly if we add one.

---

## 2. Outlook — do not bundle a marketplace skill

Document in `docs/DESKTOP.md` and in the Skills empty-state:

| Need | Use |
| ---- | --- |
| Read / search mailbox | Synaplan web: Connections → Microsoft 365 or IMAP. Chat / Saved Tasks (`email_search`) |
| Draft / send from Outlook UI | Synamail add-in |
| Calendar write | Synaplan Phase M Graph path (when shipped), not a desktop COM skill |
| “Control Outlook.exe” | Out of v1. Linux incompatible |

A community Graph+curl skill may be **user-installed** (Sprint B3) at their
risk. We do not vendor it, do not put Microsoft tokens in the desktop
keychain in v1, and do not add a second OAuth stack.

---

## 3. Tests

- Bundled `pptx` parses (frontmatter valid, name `pptx`).
- Doctor: missing python → skill shows blocked, not offered to the model.
- Bash deny: `curl https://example.com` from a fixture skill → denied.
- Upload-to-Sources: mock `/api/v1/files` 201 (if the button ships).
- Docs: `BUNDLED_SKILLS.md` lists license + SHA.

---

## 4. Exit criteria

1. Bundled pptx is visible, licensed, and SHA-pinned.
2. Hermetic create-pptx test green in Linux CI.
3. At least one real OS has manual evidence of a readable deck.
4. UI and docs never claim “Outlook automation” for the desktop app.
5. `make ci-local` green.
