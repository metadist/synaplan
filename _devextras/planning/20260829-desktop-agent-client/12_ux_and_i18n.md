# UX and four-language comprehension

**Status:** Draft 2026-08-29. Copy is reviewed **before** the Vue is built.
Applies to Synaplan web (Channels → Desktop) and to `synaplan-desktop`.

This is the first Synaplan surface that can run **programs on the user’s
computer**. If people do not understand what they installed, which folders
are visible, and how to revoke the computer, the feature is a liability.

---

## 1. The five questions every screen must answer

| # | Question | Where |
| - | -------- | ----- |
| 1 | **What is this computer allowed to do?** | Pairing page + Desktop “This computer” (folders + skills) |
| 2 | **Which skills can run programs here?** | Skills list: enabled toggle + “may run programs” |
| 3 | **Where do files go?** | Named out folder (`Synaplan/out` or the user’s write folder) |
| 4 | **Is this computer still connected?** | Device list last-seen; client 401 copy |
| 5 | **How do I stop it?** | **Revoke** on the web (kills the key). Disable skill. Quit app |

If a design needs a sixth primary control, cut scope.

---

## 2. Canonical terminology — all four locales

Proposed translations for native-speaker review (row L1 in the breakdown).
Do not treat DE/ES/TR as final until that review.

| Concept | EN | DE | ES | TR |
| ------- | -- | -- | -- | -- |
| The extra app | **Synaplan Desktop** | Synaplan Desktop | Synaplan Desktop | Synaplan Desktop |
| The Channels page | **Desktop** | Desktop | Escritorio | Masaüstü |
| Pairing action | **Pair this computer** | Diesen Computer verbinden | Vincular este equipo | Bu bilgisayarı bağla |
| Pairing code | **Pairing code** | Verbindungscode | Código de vinculación | Eşleme kodu |
| One machine | **This computer** | Dieser Computer | Este equipo | Bu bilgisayar |
| Agent Skills folder | **Skill** | Skill | Skill | Skill |
| Enable a skill | **Enable** | Aktivieren | Activar | Etkinleştir |
| Install | **Install skill** | Skill installieren | Instalar skill | Skill yükle |
| Bundled | **Included** | Enthalten | Incluida | Dahil |
| Community | **Installed by you** | Von dir installiert | Instalada por ti | Sizin yüklediğiniz |
| Allowlisted folder | **Folder this app may use** | Ordner, den diese App nutzen darf | Carpeta que esta app puede usar | Bu uygulamanın kullanabileceği klasör |
| Write target | **Save files here** | Dateien hier speichern | Guardar archivos aquí | Dosyaları buraya kaydet |
| Revoke | **Disconnect** | Trennen | Desconectar | Bağlantıyı kes |
| Queued web job | **Waiting for this computer** | Wartet auf diesen Computer | Esperando a este equipo | Bu bilgisayar bekleniyor |
| Run on device | **Run on this computer** | Auf diesem Computer ausführen | Ejecutar en este equipo | Bu bilgisayarda çalıştır |
| Doctor | **Check this computer** | Computer prüfen | Comprobar este equipo | Bu bilgisayarı denetle |

**Skill** stays untranslated (loanword). Do not use *Fertigkeit*,
*habilidad*, or *beceri* — those mean human ability and collide with
memory categories.

### 2.1 Words that must never appear in primary UI copy

`Claude`, `Claude Code`, `Anthropic`, `Agent37`, `DAG`, `TaskRunner`,
`SkillDescriptor`, `shell`, `Bash`, `tool_use`, `MCP` (except an advanced
line on the AI Agents page), `sk_`, `pairing token`, `lease`,
`brogent`, `Tauri`.

Secondary/docs/admin may use precise terms. The pairing dialog and the
Skills list may not.

“Claude-style” is allowed **once** in English docs (`docs/DESKTOP.md`),
not in the product UI.

### 2.2 Terms that already exist — do not invent synonyms

| Existing | Keep |
| -------- | ---- |
| Channels | Nav parent of Desktop |
| API Keys | Still the generic key page; desktop keys also appear there as `Desktop — {name}` |
| AI Agents | Messages gateway page — unchanged purpose |
| Sources | Where uploaded pptx land |
| Connections / Microsoft 365 | Outlook path |
| Saved Task | Unrelated; do not call a desktop job a Saved Task |

---

## 3. Screen-by-screen

### 3.1 Channels → Desktop (web)

One page. No new rail item.

- Short paragraph: Synaplan Desktop is a separate app for this computer.
  It uses your Synaplan account. It can run skills you install.
- Button: **Pair this computer** → dialog with code, minutes left,
  “Open Synaplan Desktop and enter this address and code.”
- Table of computers: name, last seen (relative), **Disconnect**.
- Sprint A3: count of waiting jobs.

Flag off (admin): “Desktop access is turned off for this instance.”
Flag off (user, global on): hide nav.

**While Phase B does not exist (Sprints A2–A3).** The server ships first, so
for several weeks the page describes an app nobody can download. Copy must not
pretend otherwise, and must not link a release page that 404s:

| State | EN copy intent |
| ----- | -------------- |
| Flag on, no client released | “Synaplan Desktop is a separate app for your computer. It is not available for download yet.” Pairing controls stay visible for testers (a code is harmless without a client) |
| Flag on, client released (`DC5`) | Replace with the install sentence + link. Same key, new value — a value-only i18n change in all four locales |

Never ship a **Download** button before a binary exists. A dead button is the
one UX bug this ordering could have introduced.

### 3.2 Desktop app — pair

Three fields only. Errors in plain language:

- Wrong code: “This code is wrong or has expired. Create a new one in
  Synaplan.”
- Flag / 404: “Desktop access is turned off.”
- TLS: “Use an https address.”

### 3.3 Desktop app — chat

Looks like a small assistant, not the full Synaplan shell. No token
meter in v1 unless we reuse an existing pattern cheaply. Failures:
disconnected / budget / gateway off.

When a skill is working: a compact **Working…** card, not a dump of
Python stderr (stderr behind “Details”).

### 3.4 Desktop app — skills

List + install. Install confirm must include the supply-chain sentence
from Sprint B3. Bundled `pptx`: readiness (Python found/missing).

Outlook: a help link “Mail and calendar stay in Synaplan” — not a
fake Outlook skill row.

### 3.5 Desktop app — this computer

Allowlisted folders, out folder, last check-in, “Disconnect from the
Synaplan website.” Do not put Revoke in the client only (client cannot
delete the server key if already stolen).

---

## 4. Honesty rules

- Do not say “works with every skill on the internet.”
- Do not say “Outlook” unless the sentence points at M365 / Synamail.
- Do not say Linux can control the Outlook application.
- Queued jobs: “Waiting for this computer”, never “the assistant is
  typing” as if the web turn were still generating the file.

---

## 5. Locale process

1. **L1** — native-speaker pass on the table in §2 before pairing UI
   merges (can be a comment on the PR from a speaker, not a blocking
   agency).
2. Every string PR updates all four files.
3. Desktop repo gets its own locale-parity test (same idea as
   `frontend/tests/unit/i18n/`).
4. Placeholders `{name}`, `{minutes}` must match across locales.

---

## 6. Comprehension gate (manual, before Sprint B4 ships)

Give a non-engineer the pairing page + Skills page in DE or ES. They
must answer §1 without help. If they cannot, fix copy, not the user.
