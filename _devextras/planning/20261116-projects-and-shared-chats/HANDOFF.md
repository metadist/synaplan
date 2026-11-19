# HANDOFF — Projects and shared chats

Stand: 2026-11-19, `main` @ `89694c4b2`. Geschrieben für einen Agenten ohne Zugriff
auf den bisherigen Chat. Jede Aussage wurde gegen `main` und die Dateien in diesem
Ordner geprüft. Was nicht geprüft wurde, steht unter "Nicht verifiziert".

Lies zuerst: `/AGENTS.md` (die Schleife und das Gate), danach in diesem Ordner
`STATUS.md` (Schrittprotokoll), `04_sprints.md` (die Briefs PJ0–PJ28),
`00_master_plan.md` §0 (Entscheidungstabelle, ☐ = offen) und `03_contracts.md`.

## 1. Ziel und aktuelle Aufgabe

**Ziel (Planungsordner):** Projekte (Quellen und Instruktionen für mehrere Chats) und
gemeinsame Chats für Synaplan, dazu ein Chat, der als Lauf ("Loop", mehrere Schritte)
beliebig lange arbeiten darf. 28 Schritte PJ0–PJ28 in Phasen 0, A–F.

**Auftrag des Owners:** die Schritte der Reihe nach umsetzen, nach `AGENTS.md`,
mit Blick auf Usability und vor allem Sicherheit.

**Stand:** PJ0–PJ4 sind auf `main` (Phase 0 und A fertig). Phase B (PJ5) ist blockiert,
weil AU26 und AH8 fehlen. Der Owner hat am 2026-11-19 entschieden: als Nächstes **AU26**,
dann **AH8a**, **AH8b**, dann PJ5 (siehe Abschnitt 9).

| Schritt | Zustand | PR |
| ------- | ------- | -- |
| PJ0 Plan | fertig, gemergt | #3133 |
| PJ1 Corpus und Runner | fertig, gemergt | #3141 |
| PJ2 Spike "ein Chat-Turn als Run-Einheiten" | fertig, gemergt | #3142 |
| Loop-Vertrag (Ledger, Guard, Ende eines Laufs) | fertig, gemergt (Doku) | #3151 |
| PJ3 Projekte mit Quellen und Instruktionen (API) | fertig, gemergt | #3152 |
| PJ4 Projektseite, Projekte im Chats-Panel | fertig, gemergt | #3186 |
| AU26 ein Agent-Loop (Zeile 22) | im Review, Branch `refactor/one-agent-loop` | — |
| PJ5–PJ28 | nicht begonnen; zuerst AU26, AH8a, AH8b | — |

## 2. Bisherige Implementierungen

### Fertig implementiert und gemergt

- **PJ0:** Plan in diesem Ordner (`00`–`04`, `STATUS.md`).
- **PJ1:** `backend/tests/Eval/ChatLoop/` mit `corpus.json` (40 Turns: 10 simple,
  8 search, 8 files, 8 multi_step, 6 research), vier synthetischen Dokumenten, drei
  geskripteten Chats und `run.php`. `StackClient.php` meldet sich wie der Web-Client
  an, sendet Turns an `POST /api/v1/messages/stream` und liest Run-Daten. Heutige
  Zahlen stehen in `STATUS.md` (z. B. `simple`: 0.0301 Kosten, erstes Token 2,56 s,
  Total 3,64 s; Vergleichsbasis für G1).
- **PJ2:** Messung, dass "jeder Turn über die Pump-Queue" unter Last G1 verletzt
  (16 gleichzeitige Turns: Faktor 30 beim ersten Text). **§0 #21 bleibt:** ein
  einfacher Turn läuft im Request, nur Turns mit Tool-Aufruf gehen in die Queue. Der
  Zwei-Stunden-Prototyp (G7) hat G7 **nicht** erfüllt (falsche Summe, Ziel verloren,
  32 doppelte Reads); die Folgerungen für Contract §1.5 stehen in `STATUS.md`.
  Skripte: `spike/turn-latency.php`, `spike/long-run.php` (kein Produktcode).
- **PJ3 (Projekte-API, Backend):** siehe unten.

### PJ3 im Detail (alles in `main`)

Migration `backend/migrations/Version20261117120000.php` (rohes idempotentes SQL, keine
Fremdschlüssel):

- Tabelle `BPROJECTS`: `BID`, `BUUID` (CHAR 36, unique), `BUSERID`, `BNAME` (120),
  `BDESCRIPTION` (500, null), `BINSTRUCTIONS` (TEXT, null), `BGROUPKEY` (64),
  `BCREATED`, `BUPDATED`; Index `idx_project_user`.
- `BCHATS` neu: `BPROJECTID` (BIGINT null), `BPROJECTSHARED` (TINYINT, default 0),
  `BMEMORYMODE` (VARCHAR 20 null), Index `idx_chat_project`.
- `BCONFIG` (`BOWNERID=0`, Gruppe `PROJECTS`, Setting `ENABLED`) = `'0'`.
  `down()` entfernt alles wieder.

Backend (neue Dateien, Pfade unter `backend/src/`):

| Datei | Aufgabe |
| ----- | ------- |
| `Entity/Project.php`, `Repository/ProjectRepository.php` | Entity (Datumsspalten `datetime_immutable`) und Queries |
| `Controller/ProjectController.php` | 13 Routen unter `/api/v1/projects`, Namen `api_projects_*`; nur HTTP, `respond()` übersetzt `ProjectException` in JSON |
| `Module/Chat/ProjectsModule.php` | FeatureModule; Env-Schlüssel `PROJECTS_ENABLED`, BCONFIG `PROJECTS.ENABLED` |
| `Service/Project/ProjectsConfig.php` | Schalter und Grenzen (siehe Abschnitt 5) |
| `Service/Project/ProjectService.php` | Anlegen, Ändern, Löschen (`chats=keep|delete`) |
| `Service/Project/ProjectSourceService.php` | Quellen: Datei, Link, Text; Refresh, Entfernen |
| `Service/Project/ProjectSourceIndexer.php` | speichert Link- und Textquellen, indexiert unter dem Projektordner, prüft Rate-Limit und Speicherquote **vor** dem Abruf |
| `Service/Project/ProjectSource.php` | Quell-DTO; Zustände `reading`, `ready`, `failed` |
| `Service/Project/ProjectChatScope.php` | liefert für einen Chat den Pin (`ProjectTurnContext`) oder null |
| `Service/Project/ProjectChatMembership.php` | Chat in ein Projekt aufnehmen oder entfernen |
| `Service/Project/ProjectPresenter.php`, `ProjectException.php`, `ProjectTurnContext.php` | Darstellung, Fehlercodes, Turn-Kontext |
| `Service/Iam/Erasure/ProjectEraser.php` | Account-Löschung entfernt die Projekte |

Geänderte bestehende Dateien: `Entity/Chat.php` (Projektfelder), `Entity/File.php`
(`SOURCES` += `project_file`, `project_link`, `project_text`),
`Service/Chat/Stream/StreamTurnPreparer.php` (neuer Konstruktorparameter
`ProjectChatScope`, `withProject`), `Service/Message/Chat/ChatSystemPromptAssembler.php`
und `Service/Prompt/ChatPromptBlocks.php` (`projectInstructions`),
`Service/File/FileGroupDeletionService.php` (`deleteFile` ist jetzt public),
`Seed/ModuleGateSeeder.php` (`projects` in `DEFAULT_ON`), `config/services.yaml`,
`config/packages/nelmio_api_doc.yaml` (Schemas `Project`, `ProjectSource`,
`ProjectChat`), `docker-compose.minimal.yml` und `backend/.env.minimal`,
fünf `frontend/src/i18n/locales/*/admin.json` (`modules.projects.label`), mehrere
Snapshots und Architekturtests (Liste: `git show --stat d3aeb80d6 e72654298`).

Tests: `tests/Unit/Service/Project/{ProjectChatScopeTest,ProjectChatMembershipTest,ProjectSourceServiceTest}.php`,
`tests/Unit/Service/Chat/Stream/StreamRagGroupKeyTest.php` (Pin-Tests),
`frontend/tests/e2e/tests/minimal-stack.spec.ts` (Modulzahl).

### Teilweise umgesetzt

- Die Spalten `BPROJECTSHARED` und `BMEMORYMODE` existieren und haben Getter in
  `Chat.php`, werden aber nirgends gesetzt oder gelesen. Reserviert für PJ20–PJ22.

### Noch offen

PJ5–PJ28. PJ4 (#3186) hat das Frontend gebracht (`views/ProjectView.vue`,
`components/projects/*`, `stores/projects.ts`, `services/api/projectsApi.ts`) und die
Spalte `BPROJECTS.BPINNEDAT` (Migration `Version20261119120000`).

## 3. Wichtige Architektur- und Designentscheidungen

- **Quellen sind normale Dateien** (`BFILES`) unter dem Wissensordner des Projekts
  (`BPROJECTS.BGROUPKEY`, Form `project:<uuid>`). Es gibt keine Quelltabelle.
  Dadurch gelten Indexierung, Speicherquote und Löschlogik der Dateien weiter.
- **Der Ordner-Pin kommt vom Server.** `StreamTurnPreparer::withProject` setzt
  `rag_group_key` aus `ProjectChatScope::forChat($state->chat)` und überschreibt
  damit jeden `ragGroupKey` des Requests. Zusätzlich gehen die Optionen
  `project_instructions` und `project_name` in den Turn.
- **Instruktionen** stehen im System-Prompt (`ChatSystemPromptAssembler::opening()` ruft
  `ChatPromptBlocks::projectInstructions`). Gekürzt auf
  `PROJECTS.INSTRUCTIONS_MAX_CHARS`.
- **Flag aus = 404 und Plain-Chat.** `ModuleGateListener` antwortet nur dann mit 404,
  wenn die Gate-Zeile `MODULES.GATE_<ID>` an ist; deshalb prüft der Controller das
  Flag zusätzlich selbst (`deny()`: erst Flag → 404 `feature_not_configured`, dann
  Login → 401). Ist das Flag aus, behandelt `ProjectChatScope` einen Chat als normalen
  Chat (kein Pin, keine Instruktionen).
- **Abweichung vom Brief (§10):** Ein Chat tritt über `POST /projects/{uuid}/chats`
  (neuer Chat), `PUT /projects/{uuid}/chats/{chatId}` (aufnehmen) und
  `DELETE /projects/{uuid}/chats/{chatId}` (entfernen) bei. `POST /chats` und
  `PATCH /chats/{id}` blieben unverändert, weil `ChatController` über dem
  Größen-Ledger liegt. In `STATUS.md` vermerkt.
- **Löschen mit `chats=keep`** (Standard): Chats werden zu normalen Chats
  (`BPROJECTID` null) und sehen die Quellen nicht mehr; `chats=delete` löscht sie.
  Ungültiger Wert → 422.
- **Create nimmt nur `name` und `description`.** `instructions` werden per `PATCH`
  gesetzt. So steht es in der OpenAPI-Beschreibung; kein Fehler.
- **Gemeinsame OpenAPI-Schemas** müssen in `config/packages/nelmio_api_doc.yaml` unter
  `components.schemas` stehen. Eine DTO-Klasse mit `#[OA\Schema]` wurde nicht
  gescannt (`$ref not found`, HTTP 500 auf `/api/doc.json`).
- **Sicherheit der Link-Quellen:** Abruf über `UrlContentService::fetchForReading`
  (maximal 200 000 Zeichen); Rate-Limit und Quote werden vor dem Abruf geprüft;
  ein 403 endet als `failed` mit Grund (z. B. "HTTP 403"). Der Grund ist auf 500
  Zeichen gekürzt.

## 4. Betroffene Dateien (Orientierung für AU26 und AH8)

- AU26: `backend/src/AI/Messages/Tools/GatewayToolLoop.php` (im Size-Ledger),
  `backend/src/AI/OpenAI/OpenAiGatewayToolLoop.php`,
  `backend/src/Service/Document/ChatToolLoop.php`,
  `backend/src/Service/Tool/Execution/ToolLimits.php`, der Snapshot `tool_loops.json`.
- AH8a: `backend/src/Service/Research/Run/*` (`ResearchLease`, `ResearchReaper`,
  `ResearchUnitWorker`, `ResearchStateStore`), `Service/Chat/Run/ChatRunService.php`
  (heute re-attachbarer Chat-Turn), Contract `../20261023-agent-handling/03_contracts.md`.

## 5. Technische Details, APIs, Datenbank

**Routen** (alle `/api/v1/projects`, Cookie-Login, Flag muss an sein):

| Methode und Pfad | Zweck |
| ---------------- | ----- |
| `GET /` | Liste, zuletzt geändert zuerst |
| `POST /` | anlegen `{name, description?}` (201) |
| `GET /{uuid}` | lesen |
| `PATCH /{uuid}` | `name`, `description`, `instructions` ändern |
| `DELETE /{uuid}?chats=keep\|delete` | löschen |
| `GET /{uuid}/sources` | Quellen |
| `POST /{uuid}/sources` | Datei (multipart `files[]`), oder JSON `{type:"link",url}` oder `{type:"text",title,text}`; Antwort 201 mit `sources` und `errors` |
| `POST /{uuid}/sources/{fileId}/refresh` | Link neu lesen |
| `DELETE /{uuid}/sources/{fileId}` | Quelle entfernen |
| `GET /{uuid}/chats` | Chats des Projekts |
| `POST /{uuid}/chats` | neuen Chat im Projekt anlegen `{title}` |
| `PUT /{uuid}/chats/{chatId}` | Chat aufnehmen |
| `DELETE /{uuid}/chats/{chatId}` | Chat herauslösen |

Die `{uuid}` ist die Projekt-UUID, in den JSON-Antworten das Feld `id`
(`{"success":true,"project":{"id":...,"name":...,"description":...,"instructions":...,"createdAt":...,"updatedAt":...}}`).
Fehlercodes: 401, 404 (kein Projekt oder Flag aus, Code `feature_not_configured`), 409
(Limit oder Speicher), 422 (ungültige Eingabe), 429 (zu viele Quellen kurz hintereinander).

**Grenzen** (`ProjectsConfig`, BCONFIG-Gruppe `PROJECTS`, Owner 0; Default greift,
wenn nichts gesetzt ist): `MAX_SOURCES` = 50, `INSTRUCTIONS_MAX_CHARS` = 4000. Fest im
Code: Titel 200, Text 500 000 Zeichen, Link-Abruf 200 000 Zeichen.

**Schalter:** `PROJECTS.ENABLED` (DB, Default `0` durch die Migration) und
`PROJECTS_ENABLED` (Env, optional, Default leer; gesetzt gewinnt vor der DB).
`FEATURE_MODULES_GATE_PROJECTS=true` steht nur in der Minimal-Konfiguration. **Keine
neue Pflicht-Env-Variable für Production.** Die Gate-Seeder-Zeile für `projects` ist
an (`DEFAULT_ON`).

**Zod und TypeScript:** `make -C frontend generate-schemas` erzeugt
`frontend/src/generated/api-schemas.ts` (gitignored). Jeder Schritt mit neuer API muss das ausführen und die
Typen daraus über `httpClient` und `services/api/` verwenden; keine handgeschriebenen
Payload-Interfaces.

## 6. Bekannte Probleme und Einschränkungen

- **Flag aus.** `PROJECTS.ENABLED` bleibt 0 bis PJ28 (§0 #73).
- **Chats ohne eigene Projekt-Zuordnung über `/chats`:** `POST /chats` und
  `PATCH /chats/{id}` kennen kein Projekt (bewusst, siehe Abschnitt 3). PJ4 nutzt die
  `PUT`/`DELETE`-Routen unter `/projects/{uuid}/chats/{chatId}`; PJ20/PJ22 ebenso.
- **Nicht geprüft:** ein E2E-Lauf für PJ3 (`make test-e2e`), weil keine UI und keine
  von der UI genutzte Route geändert wurde; CI lief auf #3152 grün (`All Checks Passed`).
- **Nicht geprüft:** PDF-Quelle über multipart-Upload im echten Chat (nur Textquelle
  wurde im echten Chat getestet; Datei-Pfad ist durch Unit-Tests und denselben
  Indexierer abgedeckt, aber nicht per Hand durchgespielt).
- **Merge-Hinweis:** #3151 und #3152 wurden auf ausdrückliche Anweisung des Owners per
  Admin-Override ohne Review-Freigabe gemergt (Checks grün, Copilot ohne Kommentar).
  Das gilt nur für diese beiden PRs; für weitere PRs gilt wieder: der Owner mergt.
- **PJ2-Prototyp:** G7 nicht erfüllt, echter Lauf im Worker mit Lease und Neustart und
  ein zweites Dokument und Modell stehen aus (siehe `STATUS.md`).

## 7. Getestete und verworfene Ansätze

- **`ProjectSchemas`-DTO mit `#[OA\Schema]`:** nicht gescannt → Schemas wanderten in
  `nelmio_api_doc.yaml`, DTO gelöscht.
- **Flag nur über `ModuleGateListener`:** reichte nicht (antwortet nur bei gesetzter
  Gate-Zeile) → Prüfung zusätzlich im Controller.
- **`File::setSource` fiel für unbekannte Quellen auf `web_upload` zurück** → die drei
  Projektquellen in `File::SOURCES` aufgenommen.
- **Datumsspalten als `datetime`** erzeugten Schema-Drift → `datetime_immutable`.
- **Verbindungs-Beitritt über `POST /chats` und `PATCH /chats/{id}`:** verworfen wegen
  der Größe von `ChatController`.
- **PJ2:** "Jeder Turn über die Pump-Queue" verworfen (Last, G1).
- **Demo-Account** (`demo@synaplan.com`) hat kein Default-Modell → Antworten sind
  Demo-Texte des `TestProvider`. Für echte Antworten den Admin-Account nehmen.

**Durchgespielt auf dem Dev-Stack (Stand 2026-11-17, über die API, Flag danach wieder 0,
Testdaten gelöscht):** Flag aus → 404. Als `admin@synaplan.com`: Projekt angelegt,
Instruktion per PATCH ("one short sentence, end with BANANA"), Textquelle "Zorblax
warranty lasts 17 months …" → `ready`, Chat im Projekt, Frage → Antwort "The Zorblax
warranty lasts 17 months and covers only the flux capacitor housing [1], BANANA.",
Anbieter `anthropic claude-sonnet-5-5`, `ragSources` mit `groupKey: project:<uuid>`.
Link mit 403 → `failed` mit Grund. Löschen mit ungültigem `chats` → 422; mit
`chats=keep` bleibt ein Plain-Chat (`BPROJECTID` null), Quellen weg.

## 8. Offene Aufgaben, nach Reihenfolge

1. **AU26** (Zeile 22): ein Tool-Loop für Dokument-Chat und beide Gateways, Grenzen als
   Profil-Eingabe (§0 #69).
2. **AH8a** (Zeile 25): Run-Kernel und `BRUNS` aus `Service/Research/Run/*` extrahiert,
   ohne Nutzer; Migration mit `BNOTES` und `chat` als `BKIND`-Wert (§0 #63).
3. **AH8b**: Research läuft auf dem Kernel, ohne Verhaltensänderung.
4. **Phase B (PJ5–PJ11).** Vor PJ6: #12, #14, #15; vor PJ7: #27; vor PJ9: #31;
   vor PJ10: #20; vor PJ11: #65. `LOOP.ENABLED` bleibt 0, bis PJ6 und PJ8 gemergt sind (§0 #70).
5. **Phase C (PJ12–PJ19)** gemeinsame Chats, noch nicht beauftragt. Offen: #34–#37, #40,
   #45, #46, #67, Schema `BMESSAGES.BAUTHORID` u. a. (#63); Notion-Spike #49 (PJ18).
6. **Phase D (PJ20–PJ22)**: #54–#56. **Phase E (PJ23–PJ27)**: #58–#62. **PJ28**: Flags an.

Ask-first laut `AGENTS.md`: Schemaänderungen, neue Abhängigkeit, Docker/CI/Build-Konfiguration,
neuer AI-Provider. Freigegeben sind nur die Migrationen von PJ3 und die `BRUNS`-Migration von
AH8a (Zeile 25 §0 #9, hier §0 #63).

## 9. Exakt nächster Schritt: AU26 (umgesetzt, im Review; danach AH8a)

1. `git checkout main && git pull --ff-only`, Branch `refactor/one-agent-loop`.
2. Brief: `../20261021-architecture-update/S4_one_tool_path.md` Abschnitt AU26, Contract
   `../20261021-architecture-update/03_contracts.md` §4 (Loop und Adapter).
3. Tests zuerst: `ToolLoopCharacterizationTest` und `tool_loops.json` bleiben unverändert;
   neuer Unit-Test: Loop ohne Iterationsgrenze und ohne Deadline endet erst bei einer
   Antwort ohne Tool.
4. `AgentToolLoop` mit drei Adaptern (`DocumentToolLoopAdapter`, `MessagesCompleteAdapter`
   und `MessagesStreamAdapter`, `OpenAiToolLoopAdapter`); Grenzen als `ToolLoopLimits` übergeben, `ToolLimits` liefert die
   heutigen Defaults; die drei Kopien der Schleife und der Konstanten sind weg;
   `GatewayToolLoop` verlässt den Size-Ledger (nur Entfernungen).
5. Gate: `make ci-local` ungefiltert, `make test-e2e` (Chat betroffen), commit
   `refactor(tools): one agent loop for document chat and both gateways`, PR, auf Copilot und
   `All Checks Passed` warten, Threads beantworten, nicht mergen.
6. `STATUS.md` der Zeile 22 und dieses Ordners im selben PR aktualisieren.

## 10. Anforderungen und Entscheidungen des Owners

- Antworten auf Deutsch, knapp, Ergebnis zuerst (der Owner liest oft auf dem Telefon).
- `AGENTS.md` ist verbindlich. Kein AI-Zusatz in Commits und PRs, solange `AGENTS.md`
  das fordert, nicht auf `main` pushen, nicht force-pushen auf `main`/`master`,
  Merge-Konflikte von Hand, nie `--ours`/`--theirs` auf Code.
- Sicherheit zuerst: nie einen Provider-Key oder ein Secret ausgeben, loggen oder
  committen. Beim Prüfen von Umgebungsvariablen keine Werte oder Präfixe ausgeben.
- Der Owner mergt. Ausnahme war eine ausdrückliche Anweisung für #3142, #3151 und
  #3152 (Admin-Override); sie gilt nicht weiter.
- Auf Copilot und CI wird gewartet, wie `AGENTS.md` es verlangt (Owner, 2026-11-19).
- Die Playwright-Läufe laufen beim Owner nicht auf diesem Rechner. Wie der Lauf
  gestartet wird, steht in den persönlichen Setup-Notizen des Owners (Agent-Memory), nicht
  in diesem Repository. Nicht auf dem lokalen Mac starten, wenn die Notizen etwas anderes
  sagen; im Zweifel den Owner fragen.
- §0-Entscheidungen des Owners (Quelle: `00_master_plan.md` §0, "Yes — owner"):
  Projekte ohne Vorlagen, Quellen einmal indexiert; das Modell entscheidet, wie lange
  und wie es arbeitet; kein festes Zeitlimit, keine Maximalzahl an Schritten; als
  einzige Leitplanken ein Kostenlimit und Kreiserkennung, beide enden mit einem
  Ergebnis; Web-Chat und Projekte, nicht das Widget; das Chat-Modell für alles;
  Notizen mit sichtbarem "Conversation summarized"; Dateien per ID im ganzen Chat;
  Rückfragekarte mit Optionen und freiem Text; gemeinsame Chats mit mehreren
  Schreibenden; keine persönlichen Erinnerungen in gemeinsamen Chats; Apps standardmäßig
  aus; Push aufs Telefon, nichts im Browser. Offene ☐-Zeilen dort **nicht** selbst
  entscheiden.
- PJ3 wurde vom Owner mit "alle Zeilen für PJ3" (§0 #4–#7) und "Ja" für die
  Migration freigegeben. Am 2026-11-19 kamen #21–#25 und #68–#73 dazu.

## 11. Lokale Umgebung (Stand der letzten Arbeit)

- Docker-Stack läuft (`docker compose ps`): `backend`, `frontend`, `db`, `pump`,
  `centrifugo` u. a. App `http://localhost:5173`, API `http://localhost:8000`. Das
  Datenbank-CLI im Container `db` heißt `mariadb`, nicht `mysql`.
- Im Dev-Stack steht `PROJECTS.ENABLED` auf `0` und es gibt keine Testprojekte
  (zuletzt geprüft: `SELECT COUNT(*) FROM BPROJECTS` = 0). Einschalten zum Testen:
  `docker compose exec -T backend php bin/console dbal:run-sql "UPDATE BCONFIG SET BVALUE='1' WHERE BGROUP='PROJECTS' AND BSETTING='ENABLED'"`,
  danach wieder auf `'0'`.
- Test- und Dev-Datenbank haben die Migration angewendet.
- Snapshots werden mit `UPDATE_CLEANUP_V2_SNAPSHOTS=1` bzw.
  `UPDATE_FEATURE_STATUS_SNAPSHOTS=1` neu geschrieben; im Container geschriebene Dateien
  gehören `root` und werden vom Datei-Sync auf dem Mac überschrieben. Dann die
  Datei im Container mit `docker compose exec -T -u root backend cat …` lesen und im
  Arbeitsverzeichnis neu anlegen. Alte Snapshot-Dateien nie mit einer leeren
  `cat >`-Ausgabe überschreiben (ist einmal passiert, per `git checkout` wiederhergestellt).
- php-cs-fixer im Container hält auf dem Mac nicht; Import-Reihenfolge-Diffs von Hand
  korrigieren.
