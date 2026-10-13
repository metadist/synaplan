# Journeys, wireframes and copy

Plan of record: [`00_master_plan.md`](./00_master_plan.md). Binding:
[`../20260907_ux_user_flows.md`](../20260907_ux_user_flows.md) — U1 (journey
before Vue), U10 (walk before merge), §6 (sprint-file contract).

Each journey is an acceptance demo, walked in a browser against local stacks
(Nextcloud `make -C synaplan-nextcloud dev-up` on `:8081`, OpenCloud
`docker compose` on `:9200` with Synaplan's `--profile oidc`, a local ownCloud
from CF1.2) and recorded as a dated STATUS entry. A journey that only passes
on Nextcloud is not walked.

---

## 1. Journeys

**J-CF-1 — Same login, nothing to connect** (CF2)
OpenCloud with organisation login. Ada opens a PDF's menu → Synaplan →
Summarize. First time only, a card: "Use Synaplan in OpenCloud? Synaplan is
your organisation's AI assistant at ai.example.org. Files you send to it are
processed there; nothing is sent until you choose an action. Signed in as
ada@example.org." **Use Synaplan** → the summary. In Synaplan → Apps →
OpenCloud: "Connected through your organisation login · last used just now".
**Turn off for OpenCloud** shows its consequence; the next Summarize in
OpenCloud says "You turned Synaplan off for this app in Synaplan." with **Open
Synaplan**; **Turn back on** restores it. Walked again on Nextcloud with
`user_oidc` after CF2.6.

**J-CF-2 — Connect from ownCloud.online** (CF1)
J-NC-1…3 on ownCloud.online: Settings → Synaplan → **Connect Synaplan** →
sign in → one card "Connect ownCloud.online at files.example.org as jdoe?" →
"Connected as Ada". The next file action runs as Ada. Email already taken in
link mode → "An account with this email exists — connect it". Disconnect from
either side returns the other side to Connect.

**J-CF-3 — Copy a generated file to my cloud** (CF3)
Synaplan → Library → Generated → a chart → **Copy to cloud** → "Nextcloud ·
files.example.org → Synaplan/Images". Toast: "Copied to Nextcloud ›
Synaplan/Images/chart.png" with **Open in Nextcloud**, which opens the file.
Nextcloud's bell: "Synaplan copied chart.png to Synaplan/Images". Copying again
gives `chart (2).png`. The file's **Sent to** list shows both. With Nextcloud
stopped: "Synaplan could not reach files.example.org. Nothing was copied." and
the recovery "In Nextcloud, open the folder and use New → From Synaplan."

**J-CF-4 — Add from Synaplan inside the cloud** (CF3)
Nextcloud, folder `/Projects/Q3` → **New → From Synaplan…** → Generated first →
pick two files → **Add 2 files** → both appear in the folder. Empty picker:
"Files Synaplan makes for you appear here." + **Open Synaplan**.

**J-CF-5 — See what the AI knows** (CF4)
Ada right-clicks `spec.pdf` → **Make searchable by AI** ("Keep it up to date"
is on) → bird with clock → bird. Hover: "Searchable by AI — in “Nextcloud ·
Specs”". She edits the file → bird with arrows → bird. The sidebar answers the
five questions. She deletes the file → it leaves Synaplan's answers and
Library at once; restoring it from the Nextcloud trash brings the bird back
without a new upload. Bob, who never made it searchable, sees no bird.

**J-CF-6 — Keep a folder searchable** (CF5)
Right-click `Specs` → **Watch with Synaplan…** → **Keep them searchable** →
"Also the 7 files already in this folder" (on) → **Start watching**. Eyes on
`Specs`; birds appear as files finish. Synaplan → Tasks → **Watched folders**:
"Specs · Nextcloud · 7 searchable"; Library: knowledge folder "Nextcloud ·
Specs" with "Kept in sync with Nextcloud › Specs". A Synaplan chat answer cites
a Specs file. A new file gets its bird within a couple of minutes. **Stop
syncing** in Synaplan → the eyes are gone in Nextcloud within a minute; files
stay searchable, as the consequence sentence said.

**J-CF-7 — Translate every new document** (CF5; use case 1)
Watch `Incoming-DE` → **Translate them** → English → save in `Incoming-EN` →
**Start watching**. A colleague drops `contract.docx`; after the quiet period
`Incoming-EN/contract.en.docx` exists. Watched folders row: "Last: 09:12 · 1
translated"; the run names the file with **Open in Nextcloud**. Saving the
same version again starts nothing. **Pause** → drop a file → nothing;
**Resume** → "Also handle the 1 file that arrived while paused?" → yes →
translated. A scanned PDF ends "contract-scan.pdf has no readable text.
Nothing was translated." and Ada gets a Nextcloud notification.

**J-CF-8 — Summarize and mail me** (CF5)
Watch `Reports` → **Summarize them and mail me**. Drop `report.pdf` → an HTML
mail "Summary: report.pdf" with 4–5 sentences, **Open in Nextcloud** and **See
this run in Synaplan**, readable on a phone. Drop 30 files → 20 mails this
hour; the row says "10 files waiting until 10:00".

**J-CF-9 — My instruction, tried on a real file** (CF5)
Watch `Invoices` → **Run my instruction**: "Extract vendor, date and total and
answer as a table" → **Try it on a file** (`invoice-0912.pdf` preselected).
Synaplan opens a chat with that invoice attached and the table as the answer.
Ada adds "also the IBAN" and checks again. **Do this for every new file in
“Invoices”** → output **Mail me** → the watch is on. A new invoice → a mail
with the table for *that* invoice; no path is typed anywhere. Switch output to
**Save next to the file** → `invoice-1003.answer.md` appears. **Edit in
Synaplan** opens the Steps editor; "Ask me before this step" makes the next
file wait in Approvals.

**J-CF-10 — Shared folder, no surprise** (CF5)
Bob opens the shared `Incoming-DE` → eyes → sidebar: "Ada's Synaplan watches
this folder: new files are translated to English and saved in Incoming-EN.
Only Ada can change this." Bob sees no Pause or Stop. Ada stops the watch in
Synaplan → Bob's eyes disappear within a minute. With the admin setting "Allow
watching shared folders" off, Ada's dialog does not offer shared folders and an
existing watch shows "Stopped — your administrator turned off watching shared
folders".

**J-CF-11 — Disconnect stops everything, reconnect resumes** (CF2 + CF5)
Ada disconnects Nextcloud in Synaplan → Apps (consequence sentence) →
Nextcloud shows Connect again; both watches read "Paused — Nextcloud is
disconnected from your Synaplan account" in Nextcloud and in Synaplan; a
dropped file is not processed. Reconnect → "Resume 2 watched folders?" →
**Resume** → files that arrived meanwhile are handled.

**J-CF-12 — New app, older Synaplan** (CF2–CF5)
Against a Synaplan without the new capabilities, each app shows summarize,
translate and the Make-searchable dialog as before — no bird, no Watch menu, no
From Synaplan, no console errors, one info line in the server log.

**J-CF-13 — New files together, with file work** (CF5)
File work is on. Ada watches `Exports` → **Run my instruction**: "Merge the
CSV files into one Excel sheet with a chart of revenue per month" → **What
should each run get? New files together** (10 minutes) → **Try it on files**
with yesterday's three CSVs. The trial chat shows the file-work card and
`revenue.xlsx` with the chart. **Do this for every new file in “Exports”** →
"This task does file work. Let it run without asking each time?" → **Yes**.
The ERP drops three CSVs at 06:00 → one run at 06:10 with the three files
attached → `revenue.xlsx` next to them; the run names its three inputs and
its output. With **Ask me each time**, the run waits in Approvals: "Run file
work on 3 files from Exports?". On an installation without file work, the
dialog never mentions it.

**J-CF-14 — Pick up files from a chat** (CF3)
In Synaplan: "Take the PDFs in my Nextcloud folder Invoices/2026 and make one
Excel table of vendor, date and total." A card: "Read 12 files from Nextcloud
› Invoices/2026" with the file chips → the table as a file → **Copy to
cloud**. Asking for `HR/Contracts`: "Synaplan may not read HR/Contracts in your
Nextcloud. Nothing was read." with **Open in Nextcloud**; there, right-click →
**Let Synaplan read this folder**; asking again works. With Nextcloud stopped:
"Synaplan could not reach files.example.org. Nothing was read."

---

## 2. Wireframes

### W1 — Consent card (all three apps, first use)

```text
┌──────────────────────────────────────────────────────────┐
│  Use Synaplan in Nextcloud?                               │
│                                                           │
│  Synaplan is your organisation's AI assistant at          │
│  ai.example.org. Files you send to it are processed       │
│  there. Nothing is sent until you choose an action.       │
│                                                           │
│  Signed in as ada@example.org (organisation login)        │
│                                        [ Not now ] [ Use Synaplan ] │
└──────────────────────────────────────────────────────────┘
```

### W2 — Apps card in Synaplan (OpenCloud, organisation login)

```text
┌──────────────────────────────────────────────────────────┐
│ [OpenCloud]  OpenCloud · cloud.example.org                │
│ Connected through your organisation login                 │
│ Last used today 09:12 · 2 watched folders · 14 searchable │
│ It can summarize, translate, make files searchable, copy  │
│ results into your OpenCloud and watch folders.            │
│ [ Watched folders ]                [ Turn off for OpenCloud ] │
└──────────────────────────────────────────────────────────┘
 Turn off → "OpenCloud stops using your Synaplan account. Your 2 watched
             folders pause. Files already in Synaplan stay."   [Cancel] [Turn off]
```

### W3 — Copy to cloud (Synaplan Library)

```text
 chart.png   ⋯ ▸ Copy to cloud ▸ ┌───────────────────────────────────────┐
                                 │ Nextcloud · files.example.org          │
                                 │   → Synaplan/Images                    │
                                 │ OpenCloud · cloud.example.org          │
                                 │   → Synaplan/Images                    │
                                 │ WebDAV · Backup NAS                    │
                                 └───────────────────────────────────────┘
 ✓ Copied to Nextcloud › Synaplan/Images/chart.png      [ Open in Nextcloud ]
```

### W4 — New → From Synaplan (cloud)

```text
┌ Add from Synaplan ─────────────────────────────── [×] ┐
│ [ Generated ▾ ]  [ Search…                        ]  │
│ ☐ Q3 plan.docx        Generated · today              │
│ ☐ chart.png           Generated · today              │
│ ☐ minutes.md          Generated · yesterday          │
│ Saved into: Projects/Q3                               │
│                              [ Cancel ] [ Add 2 files ] │
└───────────────────────────────────────────────────────┘
```

### W5 — File list (Nextcloud shown; ownCloud the same; OpenCloud via sidebar until CF-S1)

```text
 Name                              Size     Modified
 ▸ 📁 Incoming-DE  👁🐦             —        today      ← bird with eyes: watched
 ▸ 📁 Specs        👁🐦             —        today
   📄 spec.pdf     🐦               1.2 MB   today      ← searchable
   📄 roadmap.docx 🐦↻              80 KB    now        ← changed, updating
   📄 scan.pdf     🐦!              3.1 MB   Mon        ← could not
   📄 notes.txt                     2 KB     Mon        ← not searchable: nothing shown
```

(Glyphs stand for the SVG kit in `04` §A.2; each carries its tooltip and
`aria-label`.)

### W6 — Watch dialog (cloud)

```text
┌ Watch "Incoming-DE" with Synaplan ─────────────────────────── [×] ┐
│ What should happen with new files?                                 │
│  ( ) Keep them searchable                                          │
│  (•) Translate them         into [ English ▾ ]                     │
│                             save in [ Next to the original ▾ ]     │
│  ( ) Summarize them and mail me                                    │
│  ( ) Run my instruction   [ Extract vendor, date and total…      ] │
│                           [ Try it on a file ]                     │
│  ( ) Use one of my tasks                                           │
│                                                                     │
│  What should each run get?                                         │
│  (•) The new file  ( ) New files together  ( ) The whole folder    │
│  ☐ Also the 7 files already in this folder                         │
│  Only: [✓] Documents  [✓] PDF  [ ] Images     ☐ Include sub-folders │
│                                                                     │
│  Each new file is translated to English and saved next to it. The  │
│  layout of the translation is simplified. Runs on your Synaplan    │
│  account. Everyone who can open this folder sees that it is watched.│
│                                       [ Cancel ] [ Start watching ] │
└─────────────────────────────────────────────────────────────────────┘
```

The last paragraph is generated from the choice (U3): it changes with the
preset, the language, the target and the shared-folder state.

### W7 — Tasks → Watched folders (Synaplan)

```text
 Saved tasks | Watched pages | Watched folders
 ┌────────────────────────────────────────────────────────────────────┐
 │ [NC] Incoming-DE · Nextcloud · files.example.org            [on ●] │
 │ When a file arrives → translate it to English and save it next to │
 │ the original · Runs on its own (as you)                            │
 │ Last: today 09:12 · 3 done · 1 failed (“scan.pdf has no readable   │
 │ text”)                                                              │
 │ [ Open in Nextcloud ]  [ Edit task ]  [ Stop watching ]            │
 ├────────────────────────────────────────────────────────────────────┤
 │ [OC] Specs · OpenCloud · cloud.example.org                  [on ●] │
 │ New and changed files stay searchable in “OpenCloud · Specs”      │
 │ Last: today 08:40 · 14 searchable                                  │
 │ [ Open in OpenCloud ]  [ Open knowledge folder ]  [ Stop syncing ] │
 └────────────────────────────────────────────────────────────────────┘

 Empty: "Watch a folder from Nextcloud, ownCloud.online or OpenCloud:
         right-click it and choose Watch with Synaplan."  [ Show my connected clouds ]
```

### W8 — Admin: connection mode (cloud app)

```text
 How do people here use Synaplan?
 (•) Organisation login        People sign in once with your identity provider.
                               Nothing to connect.            [ Check organisation login ]
 ( ) Connect their Synaplan account   Each person connects an account they already have.
 ( ) Accounts created by the administrator   This server creates a Synaplan account per person.
 ( ) One shared account         Everyone acts as one Synaplan account and sees its files.
                                Not for teams with private documents.
 Watching folders   [✓] allowed   [ ] also on shared folders
 ⚠ Background jobs run only when someone opens a page. Watched folders need
   system cron.  How to set it up ↗
```

### W9 — Reading files in a run (Synaplan chat or task run card)

```text
┌──────────────────────────────────────────────────────────┐
│ [NC] Read 12 files from Nextcloud › Invoices/2026         │
│  [inv-0101.pdf] [inv-0102.pdf] [inv-0115.pdf] … +9        │
│  2 files were larger than 100 MB and were left out.       │
└──────────────────────────────────────────────────────────┘
 Outside what Synaplan may read:
 "Synaplan may not read HR/Contracts in your Nextcloud. Nothing was read."
                                                   [ Open in Nextcloud ]
```

### W10 — Trial result → automate (Synaplan chat, files came from a cloud folder)

```text
 … answer, file-work card, revenue.xlsx …
┌──────────────────────────────────────────────────────────┐
│ These files came from Nextcloud › Exports.                │
│ [ Do this for every new file in “Exports” ]               │
└──────────────────────────────────────────────────────────┘
 → What should each run get?  (•) New files together (10 min)  ( ) The new file
   ( ) The whole folder
 → This task does file work. Let it run without asking each time?
   [ Ask me each time ]  [ Yes ]
 → "New files in Exports are merged into one Excel with a chart and saved
   next to them. Runs on its own (as you)."            [ Cancel ] [ Start ]
```

### W11 — What Synaplan may read (cloud personal settings)

```text
 What may Synaplan read in your Nextcloud?
 (•) Only folders I watch
 ( ) Also folders I allow      Invoices/2026  [Remove]
                               Specs          [Remove]
                               [ Add a folder ]          ← native folder picker
 ( ) All my files              Synaplan can read every file you can open.
 Right-click any folder → "Let Synaplan read this folder" adds it here.
```

---

## 3. Copy — key strings in five locales

Draft for translator review in the first PR of each surface. Core strings are
reused, not re-translated; the existing key is named.

| Key idea | en | de | es | fr | tr |
| -------- | -- | -- | -- | -- | -- |
| State | Searchable by AI *(core `files.vectorState.vectorized`)* | KI-durchsuchbar | Consultable por IA | Recherchable par l’IA | Yapay zekâ ile aranabilir |
| Action | Make searchable by AI *(core `files.describeSortAction`)* | Für den Assistenten durchsuchbar machen | Hacer consultable por IA | Rendre recherchable par l’IA | Yapay zekâ ile aranabilir yap |
| Option | Keep it up to date | Aktuell halten | Mantenerlo actualizado | Le garder à jour | Güncel tut |
| Changed, updating | Changed — Synaplan is updating it | Geändert — Synaplan aktualisiert die Datei | Modificado: Synaplan lo está actualizando | Modifié — Synaplan le met à jour | Değişti — Synaplan güncelliyor |
| Menu | Watch with Synaplan… | Mit Synaplan beobachten… | Vigilar con Synaplan… | Surveiller avec Synaplan… | Synaplan ile izle… |
| Tab | Watched folders | Beobachtete Ordner | Carpetas vigiladas | Dossiers surveillés | İzlenen klasörler |
| Preset | Keep them searchable | Durchsuchbar halten | Mantenerlos consultables | Les garder recherchables | Aranabilir tut |
| Preset | Translate them | Übersetzen | Traducirlos | Les traduire | Çevir |
| Preset | Summarize them and mail me | Zusammenfassen und mir mailen | Resumirlos y enviármelos por correo | Les résumer et me les envoyer par e-mail | Özetle ve bana e-postayla gönder |
| Preset | Run my instruction | Meine Anweisung ausführen | Ejecutar mi instrucción | Exécuter mon instruction | Talimatımı çalıştır |
| Preset | Use one of my tasks | Eine meiner Aufgaben verwenden | Usar una de mis tareas | Utiliser une de mes tâches | Görevlerimden birini kullan |
| Button | Start watching | Beobachten starten | Empezar a vigilar | Commencer la surveillance | İzlemeye başla |
| Controls | Pause · Resume · Stop watching | Pausieren · Fortsetzen · Nicht mehr beobachten | Pausar · Reanudar · Dejar de vigilar | Mettre en pause · Reprendre · Arrêter la surveillance | Duraklat · Sürdür · İzlemeyi durdur |
| Copy out | Copy to cloud *(core `files.push.action`, en aligned)* | In die Cloud kopieren | Copiar a la nube | Copier vers le cloud | Buluta kopyala |
| Link | Open in {app} | In {app} öffnen | Abrir en {app} | Ouvrir dans {app} | {app} içinde aç |
| Menu | From Synaplan… | Aus Synaplan… | Desde Synaplan… | Depuis Synaplan… | Synaplan'dan… |
| Consent title | Use Synaplan in {app}? | Synaplan in {app} verwenden? | ¿Usar Synaplan en {app}? | Utiliser Synaplan dans {app} ? | {app} içinde Synaplan kullanılsın mı? |
| Status | Connected through your organisation login | Über den Organisations-Login verbunden | Conectado mediante el inicio de sesión de la organización | Connecté via la connexion de l’organisation | Kurum girişi ile bağlı |
| Action | Turn off for {app} | Für {app} ausschalten | Desactivar para {app} | Désactiver pour {app} | {app} için kapat |
| Trigger | Runs on its own (as you) *(core `assistants.triggers.runsAsYou`)* | — reuse | — reuse | — reuse | — reuse |
| Question | What should each run get? | Was soll jeder Lauf bekommen? | ¿Qué debe recibir cada ejecución? | Que doit recevoir chaque exécution ? | Her çalıştırma neyi almalı? |
| File set | The new file | Die neue Datei | El archivo nuevo | Le nouveau fichier | Yeni dosya |
| File set | New files together | Neue Dateien gemeinsam | Archivos nuevos juntos | Les nouveaux fichiers ensemble | Yeni dosyalar birlikte |
| File set | The whole folder | Den ganzen Ordner | La carpeta entera | Le dossier entier | Klasörün tamamı |
| Button | Try it on a file | An einer Datei ausprobieren | Probarlo con un archivo | L’essayer sur un fichier | Bir dosyada dene |
| Button | Do this for every new file in “{folder}” | Das für jede neue Datei in „{folder}“ tun | Hacer esto con cada archivo nuevo de «{folder}» | Faire ceci pour chaque nouveau fichier de « {folder} » | Bunu “{folder}” içindeki her yeni dosya için yap |
| Question | This task does file work. Let it run without asking each time? | Diese Aufgabe nutzt Dateiarbeit. Ohne Rückfrage ausführen? | Esta tarea usa trabajo con archivos. ¿Ejecutarla sin preguntar cada vez? | Cette tâche fait du travail sur fichiers. L’exécuter sans demander à chaque fois ? | Bu görev dosya çalışması yapıyor. Her seferinde sormadan çalışsın mı? |
| Card | Read {count} files from {app} › {folder} | {count} Dateien aus {app} › {folder} gelesen | Se leyeron {count} archivos de {app} › {folder} | {count} fichiers lus depuis {app} › {folder} | {app} › {folder} içinden {count} dosya okundu |
| Menu | Let Synaplan read this folder | Synaplan diesen Ordner lesen lassen | Permitir que Synaplan lea esta carpeta | Autoriser Synaplan à lire ce dossier | Synaplan'ın bu klasörü okumasına izin ver |
| Setting | Only folders I watch · Also folders I allow · All my files | Nur Ordner, die ich beobachte · Auch Ordner, die ich freigebe · Alle meine Dateien | Solo las carpetas que vigilo · También las carpetas que permito · Todos mis archivos | Seulement les dossiers que je surveille · Aussi les dossiers que j’autorise · Tous mes fichiers | Yalnızca izlediğim klasörler · İzin verdiğim klasörler de · Tüm dosyalarım |
| Refusal | Synaplan may not read {folder} in your {app}. Nothing was read. | Synaplan darf {folder} in deinem {app} nicht lesen. Es wurde nichts gelesen. | Synaplan no puede leer {folder} en tu {app}. No se leyó nada. | Synaplan n’est pas autorisé à lire {folder} dans votre {app}. Rien n’a été lu. | Synaplan, {app} içindeki {folder} klasörünü okuyamaz. Hiçbir şey okunmadı. |
| Empty (Watched folders) | Watch a folder from Nextcloud, ownCloud.online or OpenCloud: right-click it and choose Watch with Synaplan. | Beobachte einen Ordner in Nextcloud, ownCloud.online oder OpenCloud: Rechtsklick auf den Ordner, dann „Mit Synaplan beobachten“. | Vigila una carpeta de Nextcloud, ownCloud.online u OpenCloud: haz clic derecho y elige «Vigilar con Synaplan». | Surveillez un dossier de Nextcloud, ownCloud.online ou OpenCloud : clic droit, puis « Surveiller avec Synaplan ». | Nextcloud, ownCloud.online veya OpenCloud'daki bir klasörü izleyin: sağ tıklayıp “Synaplan ile izle”yi seçin. |

Translator notes:

- Placeholders (`{app}`, `{folder}`, `{count}`) keep their names in every
  locale.
- German form of address follows the namespace the string lands in — the
  core's `files` namespace says *du*, `assistants.triggers` says *Sie*. The
  cloud apps ship Nextcloud's informal `de`; a formal `de_DE` is optional.
- "Organisation login" is the person-facing name; admin screens may add
  "(single sign-on)".
- Banned words: [`00_master_plan.md`](./00_master_plan.md) §11.
