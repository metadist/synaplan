# Journeys and UI

**Status:** Draft 2026-10-17. Binding: UX contract U1–U12; for Desktop also
`synaplan-desktop/_extras/planning/20260910-desktop-project-companion/05_ux_and_i18n.md`
(five questions, forbidden words, rail of five) and
`20260829-desktop-agent-client/12_ux_and_i18n.md`. Copy in §6 is proposed and
goes through native-speaker review before RD3 merges. A UI step's first
deliverable is the walkthrough of its journey against these wireframes, then
the component (U1).

---

## 1. Design rules for people who are new to this

1. **One primary action per state.** Empty: *Research a topic*. Composer:
   *Start research*. Plan (Thorough): *Start research*. Result: *Open report*.
   Everything else is secondary or under **More**.
2. **Choose how thorough, never which model.** Two depths with a time hint.
   Models appear as information ("Researched with this project's models ·
   Details"), and in Models for those who look.
3. **Show the work in the person's words.** A checklist of the plan's points,
   counters ("18 found · 9 read · 2 could not be opened"), the page being read
   now. Search queries and hosts only under **Details**.
4. **Never a dead end.** Every stop or failure says what happened, what did and
   did not happen, and offers the next step (U8).
5. **The result is findable in ten seconds** from where the person works: the
   card in the thread, the Out board, the file in the project folder (U2).
6. **Trust is visible.** Every fact links to a source; opening it shows the
   exact quote. The fact-check result is one sentence on the card.
7. **Undo is a click.** Stop while running; restore an earlier version; delete
   the report; remove a file from the project (U3).
8. **No new rail item.** Research is a chip in Chat (Desktop) and a tool in the
   composer (web) (§0 #15).

---

## 2. People

| Name | Who | Uses |
| ---- | --- | ---- |
| **Ada** | Office manager in a 40-person company; uses Word and Outlook, no technical background | Desktop, web on, normal chat and Standard research |
| **Ben** | Clerk in a municipal office; must cite sources; the project must stay in EU or local models | Desktop, own documents, Web off |
| **Chris** | Independent consultant; writes reports for clients | Desktop, Thorough research, Word output |
| **Dana** | Team lead who only uses the web app | Web chat research (Phase E) |

---

## 3. Where things live

| Thing | Desktop | Web (Phase E) |
| ----- | ------- | ------------- |
| Start research | Chat composer: **Research** chip next to **Web**; empty-chat tile *Research a topic* | Composer tools menu: **Research** |
| Plan, progress, result | Cards in the thread | Card in the thread (task-card visual language) |
| The report | Side panel (`ProjectPanel`) or full width; file `out/<slug>.md` + `out/<slug>.sources.json`; Out board entry | File attached to the message; opens in the existing preview (the prompting flow's docked pane when it exists, U12) |
| Sources and quotes | Source drawer from a `[n]` chip; sources list at the end | Same, reusing the knowledge-sources list pattern |
| Research team | Models → **Research team** | Admin → AI infrastructure (RW2); people see the models on the run |
| Research rules | Project settings → **Research rules** | Assistant prompt / folder instructions |
| Running in the background | Tray tooltip; OS notification when done | Chat list shows the running chat; the run continues after the tab closes |

---

## 4. Journeys

Each journey is walked in the app (click, type, find, undo) and entered in
STATUS (U10). Copy in quotes is the English source string.

### J-RQ-1 — Ask with Web on gets a full, sourced answer (Ada, RD1 + RS1–RS2)

1. Project *Lieferanten* with **no skill enabled**, Chat, **Web** on, Research
   off.
2. Ada types: *"Was kostet ein Wartungsvertrag für Klimaanlagen in Bayern
   ungefähr?"* and presses Enter.
3. Activity: "Searching the web" → "Reading 2 pages". The answer has several
   paragraphs, names price ranges and links each source.
4. Ada types: *"Schreib die Quellen in quellen.md"*. Because the message names
   a file, the turn gets the file tools by itself — no skill needed — and the
   file is written and appears on the Out board.
5. **Find:** the answer stays in the thread; the file is on the Out board.
   **Undo:** delete the file from the Out board.

Exit: UC-03, UC-04 green, plus their German variants (UC-03-de, UC-04-de); the
walk done in a project without any enabled skill.

### J-RQ-2 — First research from an empty project (Ada, RD3 + RD5)

1. New project *Wärmepumpe*. The empty chat shows the existing tiles plus
   *Research a topic* — "Get a report with sources you can check."
2. Click → the composer switches to Research: placeholder "What do you want to
   find out?", depth **Standard** selected, sources "the web" ticked and "this
   project's files — none yet · Add files".
3. She types *"Welche Förderung gibt es 2026 in Bayern für eine Wärmepumpe im
   Einfamilienhaus?"* and presses **Start research**.
4. The plan card appears and work starts at once: three points, **Stop**.
5. The progress card ticks points off, counts sources, names the page being
   read, and says the window may be closed.
6. After about three minutes the result card: title, summary, fact-check
   sentence, **Open report**, **Make a Word document**, **More**, "Saved in this
   project: out/foerderung-waermepumpe-2026.md · Show in folder".
7. **Open report** → side panel; she clicks `[2]` → the source drawer shows the
   quote and **Open page**.
8. **Find:** next day she opens the project — the thread is in the list with a
   *Report* marker, the report is on the Out board, the file is in the folder.
   **Undo:** **More → Delete report** ("The report file is deleted from this
   project and from your Synaplan workspace. The chat stays."). The server
   copy goes with it (`DELETE /v1/research/{id}`).

### J-RQ-3 — Research over my own documents (Ben, RD7, RD3, RD5)

1. Files → **Add a folder** → picks `C:\Verträge`.
2. Dialog: "23 files: 18 PDF, 4 Word, 1 Excel · 41 MB. These files are sent to
   your Synaplan workspace so the assistant can read them. They stay in this
   project's knowledge folder until you remove them." **Send 23 files**.
3. Each row moves Sent → Reading → Ready; one row: "Could not read any text from
   this file." No row says Ready without text (desktop CHANGES C3b).
4. Chat → Research, untick "the web", ask *"Welche Verträge verlängern sich 2027
   automatisch, und mit welcher Frist kann ich kündigen?"*.
5. Result: a table (contract, renewal date, notice period, source) and Not
   confirmed: "Vertrag_C.pdf: no renewal clause found."
6. `[4]` → drawer: quote from "Rahmenvertrag_A.pdf, page 7" and **Open file**,
   which opens the original on this computer (the project remembers where each
   file came from).
7. **Undo:** Files → row → **Remove from this project**: "The assistant can no
   longer read this file. The file on your computer stays."

### J-RQ-4 — Thorough research with a question and plan review (Chris, RD4, RD9)

1. Research → **Thorough**. Under the chips: "About 15 minutes · estimated cost
   {amount}".
2. **Start research** → question card: "Which region should the comparison
   cover?" (Germany) (EU) (Worldwide), **Type my own answer**, "Skip — use your
   best judgement". He picks EU.
3. Plan card: six points, each with **Remove**, **Add a point**, sources line,
   estimate, **Cancel** / **Start research**. He removes one point, adds
   "Delivery times", starts.
4. He closes the window; the app stays in the tray (as for jobs since desktop
   #32). Tray tooltip: "Research running: 3 of 6 points".
5. Fourteen minutes later an OS notification: "Your report is ready: Supplier
   comparison for X". Click → the thread.
6. Fact-check: "35 of 37 statements are backed by a source. 2 were removed
   because no source confirmed them." **Show what was removed** jumps to Not
   confirmed.

### J-RQ-5 — Turn into a Word document, change a section, restore (Chris, RD6)

1. Result card → **Make a Word document**. The card shows "Creating the Word
   document" and then a file chip *supplier-comparison.docx* with **Open** and
   **Show in folder**; it is also on the Out board.
2. In the report, section 2 → **Change this section** → **Shorter**. A new
   version appears with "Version 2 · Show changes · Restore version 1".
3. **Restore version 1** → back; the card says "The Word document was made from
   version 2. Make it again?" (what did and did not happen, U8).

### J-RQ-6 — Something goes wrong (Ada, RD3–RD5)

| Situation | What she sees | Next step offered |
| --------- | ------------- | ----------------- |
| Web search not set up | Sources line: "the web — not set up on your Synaplan workspace" (disabled, with that reason) | Research files only, or ask an administrator |
| No web and no files | Composer: "Add files or turn on the web so there is something to research." | **Add files** |
| Budget used up during the run | Stopped card: "Stopped because your monthly AI budget is used up. The report covers 2 of 3 points." | **Open what was found** |
| No page could be opened | Report banner: "No page could be opened, so this report is based on search summaries only." | **Go deeper** with other sources |
| Offline | Progress card: "Waiting for the connection. The research continues on your Synaplan workspace." | Reattaches by itself |
| Server lost the run | "The research was interrupted on the server. Start it again — the sources found so far are kept here." | **Start again** |
| A role's model unavailable | "The reading model of this project is not available, and no other model in this project can stand in. Nothing was charged." | **Open Models** |

### J-RQ-7 — A project that stays in its world (Ben, RD8)

1. Project *Behörde*: Chat = Mistral Large 4, Documents = GLM-5.3 Flash (hosted
   in Germany).
2. Models → **Research team**: "Research in this project uses only the providers
   this project already uses." Thinking and writing: Mistral Large 4 (same as
   Chat). Reading sources: GLM-5.3 Flash. Fact-checking: GLM-5.3 — "a second
   opinion from another provider".
3. He runs a research. The run's Details list every model and "Rerank: skipped —
   no reranker is allowed in this project".
4. He switches the project to local models: the team becomes local and the
   estimate says "About 25 minutes on your workspace's own hardware".
5. If only one provider is allowed: Fact-checking shows "same provider (no other
   provider in this project)" — honest, not blocked.

### J-RQ-8 — Research mode in the web chat (Dana, RW1)

1. Composer → tools → **Research** → the composer shows depth and sources (the
   web, Library folders).
2. A research card in the thread with the plan checklist, counters and **Stop**;
   closing the tab does not stop it.
3. The report arrives as a file attached to the message, with the fact-check
   sentence; it opens in the preview; sources list with quotes.
4. **Find:** the chat list; Library → Files; Ctrl+K by title.
   **Undo:** the file's existing delete action; Stop while running.

### J-RQ-9 — Follow-up and Go deeper (Chris, RD5)

1. Under the report: **Ask about this report** → *"Und in Österreich?"* → a short
   cited answer from the same sources; when new sources were needed it says so;
   **Add to report**.
2. **Go deeper** (after a Standard report): "This continues for about 12 more
   minutes and reads up to 25 more sources. Estimated cost {amount}." **Go
   deeper** / **Cancel** → Not confirmed points become plan points; the result
   is version 2 of the same report.

### J-RQ-10 — An administrator turns research on and checks the team (admin, RW2)

1. Admin → AI infrastructure → **Research** (a tab on the existing page, health
   pill, UX contract §4.5): "Research is off. People cannot start research yet."
   **Turn on**.
2. The team per world: reading and fact-checking models with availability, the
   lead noted as "the model of each chat"; budgets per depth (searches, sources,
   minutes, cost cap).
3. **Run a sample research** → "Read 6 sources · 9 of 9 statements backed ·
   2 min 41 s · cost 0.21".
4. **Find:** people now see **Research** in the web composer and the Research
   chip in Desktop (capabilities answer 200).
   **Undo:** **Turn off** → "People can no longer start research. Research
   that is running stops after its current step and keeps what it found;
   reports already made stay and can still be opened and deleted."

---

## 5. Wireframes (Desktop)

### 5.1 Composer

```text
Research off — normal chat, unchanged
┌──────────────────────────────────────────────────────────────────────┐
│ [Attach] [Note] [Web: on] [Research]                                 │
│ Message your Synaplan assistant…                       [Mic] [Send]  │
└──────────────────────────────────────────────────────────────────────┘

Research on
┌──────────────────────────────────────────────────────────────────────┐
│ [Attach] [Note] [Web: on] [Research: on]                             │
│ What do you want to find out?                                        │
│                                                                      │
│ How thorough?  (o) Standard · about 3 min  ( ) Thorough · 15 min     │
│ Sources:  [x] the web    [x] this project's files (12 ready)         │
│                                               [Mic] [Start research] │
└──────────────────────────────────────────────────────────────────────┘
```

- **Start research** is disabled until there is a question and at least one
  source; the reason is the line under it, not a tooltip.
- Thorough adds "About 15 minutes · estimated cost {amount}" under the chips.
- The Research chip is absent when `GET /v1/research/capabilities` answers 404
  (U11).

### 5.2 Question card (Thorough, or Standard when ambiguous)

```text
┌ One question before I start ─────────────────────────────────────────┐
│ Which region should the comparison cover?                            │
│ [ Germany ]  [ EU ]  [ Worldwide ]                                   │
│ [ Type my own answer… ]                                              │
│ Skip — use your best judgement                                       │
└──────────────────────────────────────────────────────────────────────┘
```

### 5.3 Plan card

```text
Thorough — waits for Start
┌ Research plan · Thorough · about 15 minutes ─────────────────────────┐
│ I will look into:                                                    │
│   1  Which suppliers offer X in the EU                     [Remove]  │
│   2  Prices and contract terms                             [Remove]  │
│   3  Delivery times                                        [Remove]  │
│   4  Reviews and known problems                            [Remove]  │
│   + Add a point                                                      │
│                                                                      │
│ Sources: the web and 12 files in this project                        │
│ Web search goes through Brave, hosted in the US.                     │
│ Estimated cost {amount}. Nothing is emailed, saved or changed        │
│ anywhere; the models of this project read your question and the      │
│ sources it finds.                                                    │
│                                        [Cancel]  [Start research]    │
└──────────────────────────────────────────────────────────────────────┘

Standard — starts at once; the same card without Remove / Add, with [Stop]
```

The disclosure line appears only when the server reports one (`plan.disclosures`,
world rule §0 #29); a project whose world matches shows nothing there.

### 5.4 Progress card

```text
┌ Researching · Standard ─────────────────────────────────────── [Stop] ┐
│ [x] Which suppliers offer X in Germany            5 sources           │
│ [>] Prices and contract terms                     reading 3 of 6      │
│ [ ] Reviews and known problems                                        │
│                                                                       │
│ 18 found · 9 read · 2 could not be opened                             │
│ Now: reading "Preisliste 2026" — lieferant-a.example                  │
│                                                                       │
│ You can close this window. The research continues and the report      │
│ appears here.                                  [Write now] [Details]  │
└───────────────────────────────────────────────────────────────────────┘

Details (collapsed by default)
  Searched "Lieferant A Preisliste 2026" (web) — 10 results
  Read lieferant-a.example — 3 findings
  Could not open example.org — it needs a login
```

**Write now** stops gathering and writes with what was found
(`stop_gathering`). Status markers are text plus an icon, never colour alone.
When the server sends `queued` for more than 20 s the "Now:" line reads
"Waiting for a free worker on your Synaplan workspace." — the research is not
stuck, it is in line.

### 5.5 Result card

```text
┌ Report ready · 3 min · 14 sources ────────────────────────────────────┐
│ Heat pump subsidies in Bavaria 2026                                   │
│ Owners of single-family houses can receive up to 70 % of eligible     │
│ costs through the federal programme; Bavaria adds a bonus … [1][4]    │
│                                                                       │
│ Fact-check: 21 of 22 statements are backed by a source.               │
│ 1 was removed because no source confirmed it.                         │
│                                                                       │
│ [Open report]   [Make a Word document]   [More]                       │
│ Saved in this project: out/heat-pump-subsidies.md · Show in folder    │
│ Researched with this project's models · Details                       │
└───────────────────────────────────────────────────────────────────────┘

More: Slides (PowerPoint) · Table (Excel) · Email draft · Web page ·
      Translate… · Go deeper · Check for newer sources · Delete report
```

### 5.6 Report view and source drawer

```text
┌ Heat pump subsidies in Bavaria 2026 ────── [Version 1] [Word] [Close] ┐
│ Summary                                                               │
│ Owners of single-family houses can receive … [1][4]                   │
│                                                                       │
│ 1. Federal programme                                                  │
│ The base grant is 30 % of eligible costs. [1]   [Change this section] │
│ …                                                                     │
│ Not confirmed                                                         │
│ - Whether the Bavarian bonus continues after June 2026: no source     │
│   newer than March 2026 was found.                                    │
│                                                                       │
│ Sources (14)                                                          │
│ [1] Förderrichtlinie BEG EM · bafa.de · official · 2026-01-15         │
│     used for: Summary, 1                                              │
│ [4] Landesprogramm … · stmwi.bayern.de · official · 2026-03-02        │
├───────────────────────────────────────────────────────────────────────┤
│ Ask about this report…                                        [Ask]   │
│ [Shorter] [Simpler words] [Translate] [Go deeper]                     │
└───────────────────────────────────────────────────────────────────────┘

┌ Source 1 ─────────────────────────────────────────────────── [Close] ┐
│ Förderrichtlinie Bundesförderung für effiziente Gebäude              │
│ bafa.de · official · published 2026-01-15                            │
│                                                                      │
│ Used for: "The base grant is 30 % of eligible costs."                │
│ Quote: "Die Grundförderung beträgt 30 Prozent der förderfähigen      │
│ Ausgaben." — section 8.1                                             │
│                                                                      │
│ [Open page]   [Copy quote]                                           │
└──────────────────────────────────────────────────────────────────────┘
```

### 5.7 Stopped and failed cards

```text
┌ Research stopped ─────────────────────────────────────────────────────┐
│ Stopped because your monthly AI budget is used up. The report covers  │
│ 2 of 3 points.                                                        │
│ [Open what was found]                                                 │
└───────────────────────────────────────────────────────────────────────┘
```

### 5.8 Models → Research team

```text
Research team
Research in this project uses only the providers this project already uses.

  Thinking and writing   Claude Sonnet 5.5    same as Chat
  Reading sources        GPT-6 Luna           faster and cheaper for long texts
  Fact-checking          Gemini 3.8 Flash     a second opinion from another provider

                                        [Customize]  [Reset to recommended]
```

Customize turns the three rows into pickers (the catalog's chat models inside
the world, unavailable ones listed with their reason, as `ModelSlotRow` does).
Model names are catalog data; product sentences never name a provider.

### 5.9 Add a folder

```text
┌ Add a folder to this project ────────────────────────────────────────┐
│ C:\Users\Ben\Documents\Verträge                                      │
│ 23 files: 18 PDF, 4 Word, 1 Excel · 41 MB                            │
│                                                                      │
│ These files are sent to your Synaplan workspace so the assistant can │
│ read them. They stay in this project's knowledge folder until you    │
│ remove them.                                                         │
│ [ ] Include sub-folders                                              │
│ Skipped: 3 files that cannot be read (videos, archives)              │
│                                        [Cancel]  [Send 23 files]     │
└──────────────────────────────────────────────────────────────────────┘
```

The path is shown platform-native (`C:\…` on Windows, `/Users/…` on macOS),
never `~/…` on Windows.

### 5.10 Web research card (Phase E)

```text
┌ Research · Standard ────────────────────────────────────────── [Stop] ┐
│ [x] Point one        [>] Point two        [ ] Point three             │
│ 18 found · 9 read · 2 could not be opened                             │
├───────────────────────────────────────────────────────────────────────┤
│ (when done)  [report.md]  Fact-check: 21 of 22 statements are backed  │
│              [Open]  [Export to Word]  [More]                         │
└───────────────────────────────────────────────────────────────────────┘
```

Web follows U9: house tokens, `btn-primary px-4 py-2.5`, dark and V2 and
320 px; a new icon-map entry for the research action (proposed Heroicons
`DocumentMagnifyingGlassIcon`) in `docs/FRONTEND_CONVENTIONS.md`.

---

## 6. Copy (proposed, five locales)

Desktop German uses *du*, French *vous*, Spanish *tú*, Turkish *siz* (as the
existing Desktop strings).

| Key | en | de | es | fr | tr |
| --- | -- | -- | -- | -- | -- |
| Chip | Research | Recherche | Investigar | Recherche | Araştırma |
| Placeholder | What do you want to find out? | Was möchtest du herausfinden? | ¿Qué quieres averiguar? | Que voulez-vous savoir ? | Neyi öğrenmek istiyorsunuz? |
| Depth question | How thorough? | Wie gründlich? | ¿Con qué detalle? | Quel niveau de détail ? | Ne kadar kapsamlı? |
| Standard | Standard · about {minutes} min | Standard · ca. {minutes} Min. | Estándar · unos {minutes} min | Standard · environ {minutes} min | Standart · yaklaşık {minutes} dk |
| Thorough | Thorough · about {minutes} min | Gründlich · ca. {minutes} Min. | A fondo · unos {minutes} min | Approfondie · environ {minutes} min | Kapsamlı · yaklaşık {minutes} dk |
| Sources | Sources | Quellen | Fuentes | Sources | Kaynaklar |
| Web source | the web | das Web | la web | le web | web |
| File source | this project's files | die Dateien dieses Projekts | los archivos de este proyecto | les fichiers de ce projet | bu projenin dosyaları |
| Start | Start research | Recherche starten | Empezar la investigación | Lancer la recherche | Araştırmayı başlat |
| Plan title | Research plan | Rechercheplan | Plan de investigación | Plan de recherche | Araştırma planı |
| Plan lead | I will look into: | Ich prüfe: | Voy a investigar: | Je vais examiner : | Şunları inceleyeceğim: |
| Add point | Add a point | Punkt hinzufügen | Añadir un punto | Ajouter un point | Madde ekle |
| Write now | Write now | Jetzt schreiben | Redactar ya | Rédiger maintenant | Şimdi yaz |
| Stop | Stop | Stoppen | Detener | Arrêter | Durdur |
| Close window | You can close this window. The research continues and the report appears here. | Du kannst das Fenster schließen. Die Recherche läuft weiter, und der Bericht erscheint hier. | Puedes cerrar esta ventana. La investigación continúa y el informe aparecerá aquí. | Vous pouvez fermer cette fenêtre. La recherche continue et le rapport s'affichera ici. | Bu pencereyi kapatabilirsiniz. Araştırma devam eder ve rapor burada görünür. |
| Report | Report | Bericht | Informe | Rapport | Rapor |
| Open report | Open report | Bericht öffnen | Abrir informe | Ouvrir le rapport | Raporu aç |
| Word | Make a Word document | Word-Dokument erstellen | Crear un documento de Word | Créer un document Word | Word belgesi oluştur |
| Fact-check | Fact-check | Faktencheck | Verificación | Vérification des faits | Doğruluk kontrolü |
| Backed | {supported} of {total} statements are backed by a source. | {supported} von {total} Aussagen sind durch eine Quelle belegt. | {supported} de {total} afirmaciones están respaldadas por una fuente. | {supported} affirmations sur {total} sont étayées par une source. | {total} ifadeden {supported} tanesi bir kaynakla destekleniyor. |
| Removed | {removed} were removed because no source confirmed them. | {removed} wurden entfernt, weil keine Quelle sie bestätigt hat. | Se eliminaron {removed} porque ninguna fuente las confirmó. | {removed} ont été retirées, car aucune source ne les confirmait. | Hiçbir kaynak doğrulamadığı için {removed} tanesi çıkarıldı. |
| Not confirmed | Not confirmed | Nicht bestätigt | Sin confirmar | Non confirmé | Doğrulanmadı |
| Team | Research team | Recherche-Team | Equipo de investigación | Équipe de recherche | Araştırma ekibi |
| Role: lead | Thinking and writing | Denken und Schreiben | Pensar y redactar | Réflexion et rédaction | Düşünme ve yazma |
| Role: reader | Reading sources | Quellen lesen | Lectura de fuentes | Lecture des sources | Kaynak okuma |
| Role: checker | Fact-checking | Faktenprüfung | Verificación de datos | Vérification | Doğrulama |
| World | Research in this project uses only the providers this project already uses. | Recherchen in diesem Projekt nutzen nur Anbieter, die dieses Projekt schon nutzt. | La investigación en este proyecto solo usa los proveedores que este proyecto ya utiliza. | Les recherches de ce projet n'utilisent que les fournisseurs que ce projet utilise déjà. | Bu projedeki araştırmalar yalnızca bu projenin zaten kullandığı sağlayıcıları kullanır. |
| Deeper | Go deeper | Tiefer recherchieren | Profundizar | Approfondir | Daha derine in |
| Ask box | Ask about this report… | Frage zu diesem Bericht… | Pregunta sobre este informe… | Une question sur ce rapport… | Bu rapor hakkında sorun… |

Plurals: "Backed" and "Removed" use vue-i18n plural branches; branch counts may
differ per locale (Turkish keeps the singular after a numeral), placeholder
names may not (locale parity test).

**Words that never appear in product sentences** (Desktop list, still in
force): Claude, Anthropic, MCP, shell, Bash, tool_use, sk_, lease, Tauri,
protocol, capability, DEFAULTMODEL, VECTORIZE, ANALYZE, group_key. New for this
plan: evidence card, ledger, sorter, reader, lead, checker, reflect, token,
RAG, embedding, rerank, prompt. (Model names may appear as catalog data in
Models and Details.)

---

## 7. The five questions on each surface (U7)

| Surface | Who owns this? | Who else? | What will it touch? | How do I stop it? | Where did it come from? |
| ------- | -------------- | --------- | ------------------- | ----------------- | ---------------------- |
| Plan card | You, in this project (header) | No one | "Reads the web and 12 files in this project. Nothing is emailed, saved or changed anywhere; the models of this project read your question and the sources it finds." plus the world disclosure line when one applies | **Cancel**, **Remove** a point | Your question; the brief |
| Progress card | You | No one | Live counters | **Stop**, **Write now** | **Details** log |
| Result card and report | You, this project | No one unless you share the file | "Saved in this project: …" | **Delete report**, **Restore version** | Sources list with quotes, models, date |
| Research team | This project | — | "uses only the providers this project already uses" | **Reset to recommended**, Customize | The workspace's model list |
| Add a folder | You | Your Synaplan workspace's knowledge folder | "23 files are sent … stay until you remove them" | **Remove from this project** per file | The local path, shown native |

---

## 8. Empty, error and flag-off states (U5, U8, U11)

| State | Desktop | Web |
| ----- | ------- | --- |
| Server without research (capabilities 404, older server, flag off) | No chip, no tile, no team section, no rules field. A run that was active shows its `stopped_by_admin` card with what was found; saved reports stay, **Delete report** still works (the read and delete routes stay open for the owner's runs until their TTL, `03` §7.1) | No tool entry; start routes 404; existing report files and stopped cards stay readable |
| No question yet | **Start research** disabled; hint "Type a question first." | Same |
| No source possible | "Add files or turn on the web so there is something to research." **Add files** | "Turn on web search or pick a Library folder." |
| Web search not configured | "the web — not set up on your Synaplan workspace" (disabled) | Same, plus a link to the admin page for administrators |
| One Thorough run already running | Thorough disabled: "A thorough research is already running in project {name}." **Open it** | Same |
| Plan limit reached (§0 #25) | "You have used today's research runs on your plan. Standard research is available again tomorrow." | Same |
| No embedding model on the workspace (cloud install without one) | "this project's files — the workspace has no index model yet" (disabled); administrators get a link to AI infrastructure | Same |
| Web search provider outside the project's world | Disclosure line on the plan card (default) or "the web — not available in this project's world" (strict mode) | Same |
| Workspace cannot read PDFs (minimal install, no Tika) | Details: "PDF — this workspace cannot read this kind of file"; the report says which sources were skipped for that reason | Same |
| Next unit waiting for a worker | Progress "Now:" line: "Waiting for a free worker on your Synaplan workspace." | Same; the admin status page names it after 5 min |
| Research turned off by an admin while running | Stopped card: "Research was turned off on your Synaplan workspace. What was found so far is kept." | Same |
| All outcomes of `03` §7.5 | Stopped / failed card with the sentence and one next step | Same |

---

## 9. Accessibility and layout

- The Research chip and the depth choice are keyboard-operable (chip is a
  toggle button, depth a radio group); **Start research** is the default
  action on Enter.
- Progress updates go to a polite live region, at most one announcement every
  ten seconds ("Point 2 of 3 done").
- Citation chips are buttons labelled "Source 1: {title}"; the drawer traps
  focus and closes on Escape.
- Status is never colour alone: text plus marker (`[x]`, `[>]`, `[ ]` in the
  wireframes stand for icon + label).
- Light and dark themes, WCAG AA contrast; Desktop at its default window size
  and the narrow layout (cards stack, actions wrap — desktop #35); web at
  320 px and in the V2 design.

---

## 10. Exit bullets for every UI step (UX contract §6)

1. The step's journey walked in the app — click, type, find, undo — and entered
   in STATUS.
2. The result found again in ten seconds from where the person works.
3. Consequence copy for this kind in five locales (plan, start, delete, remove,
   team).
4. Empty, error and flag-off states as in §8.
5. Light and dark, narrow window (Desktop) / 320 px and V2 (web).
