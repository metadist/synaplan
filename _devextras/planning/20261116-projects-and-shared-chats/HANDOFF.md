# HANDOFF — Projects and shared chats

Stand: 2026-11-17, `main` @ `f54cb3353`. Geschrieben für einen Agenten ohne Zugriff
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

**Stand:** PJ0–PJ3 sind auf `main`. Die Aufgabe als Nächstes ist **PJ4** (Projektseite
und Projekte im Chats-Panel, siehe Abschnitt 9). Es ist kein Branch dafür angelegt,
nichts ist begonnen.

| Schritt | Zustand | PR |
| ------- | ------- | -- |
| PJ0 Plan | fertig, gemergt | #3133 |
| PJ1 Corpus und Runner | fertig, gemergt | #3141 |
| PJ2 Spike "ein Chat-Turn als Run-Einheiten" | fertig, gemergt | #3142 |
| Loop-Vertrag (Ledger, Guard, Ende eines Laufs) | fertig, gemergt (Doku) | #3151 |
| PJ3 Projekte mit Quellen und Instruktionen (API) | fertig, gemergt | #3152 |
| PJ4–PJ28 | nicht begonnen | — |

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

Alles Frontend (kein Router-Eintrag, kein Store, keine Views für Projekte; nachgesehen
mit `grep projects frontend/src/router/index.ts`: keine Treffer) und PJ4–PJ28.

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

## 4. Betroffene Dateien (Orientierung für PJ4)

Frontend-Dateien, die PJ4 laut Brief liest oder anlegt:

- Lesen: `frontend/src/components/sidebar/SidebarPanelChats.vue`,
  `ChatHistoryList.vue`, `KnowledgeFolderPicker.vue`, die Upload-Zeilen der
  Files-Seite, `docs/UX_CONTRACT.md` §4, `docs/FRONTEND_CONVENTIONS.md`.
- Neu: `views/ProjectView.vue`, `components/projects/*`, `stores/projects.ts`,
  `services/api/projectsApi.ts`, Locale-Schlüssel in allen fünf Sprachen.

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
`frontend/src/generated/api-schemas.ts` (gitignored). PJ4 muss das ausführen und die
Typen daraus über `httpClient` und `services/api/` verwenden; keine handgeschriebenen
Payload-Interfaces.

## 6. Bekannte Probleme und Einschränkungen

- **Kein UI.** Projekte sind nur über die API nutzbar; die Migration lässt das Flag aus.
  Production nicht einschalten, bevor PJ4 gemergt ist.
- **Chats ohne eigene Projekt-Zuordnung über `/chats`:** `POST /chats` und
  `PATCH /chats/{id}` kennen kein Projekt (bewusst, siehe Abschnitt 3). PJ4 "Chat in
  ein Projekt verschieben" muss die `PUT`-Route benutzen.
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

## 8. Offene Aufgaben, nach Wichtigkeit

1. **PJ4** Projektseite und Projekte im Chats-Panel (Abschnitt 9). Voraussetzung, um
   das Flag je einschalten zu können.
2. **Phase B (PJ5–PJ11)** Loop als Lauf. Wartet laut `STATUS.md` und §0 auf AU26 und
   AH8 und auf offene Entscheidungen (#12 Kostenlimit, #14 Token-Cap, #20 Planner-Karte
   entfernen, #22/#23).
3. **Phase C (PJ12–PJ19)** gemeinsame Chats. Offene Entscheidungen: #36, #37, #45, #46,
   #67; Notion-Spike #49 (PJ18).
4. **Phase D (PJ20–PJ22)** gemeinsame Projekte und Projektgedächtnis (#54, #55). Hier
   werden `BPROJECTSHARED` und `BMEMORYMODE` benutzt.
5. **Phase E (PJ23–PJ27)** Push. Ask-first: Push-Plugin (#61), Relay-Dienst (#62),
   Schema (#63). Betrifft `synaplan-apps` und `synaplan-platform`.
6. **PJ28** Doku und Flags.

Ask-first laut `AGENTS.md` (vor Umsetzung den Owner fragen): Schemaänderungen, neue
Abhängigkeit, Docker/CI/Build-Konfiguration, neuer AI-Provider. Der Owner hat die
Migration von PJ3 ausdrücklich freigegeben; das gilt nicht für weitere Schemaänderungen.

## 9. Exakt nächster Schritt: PJ4

1. `git checkout main && git pull --ff-only`, danach Branch `feat/projects-ui` von
   `main` anlegen (ein Thema, ein Branch, ein PR).
2. Brief lesen: `04_sprints.md` Zeile 78 ff. ("PJ4"), `02_journeys_and_ui.md` J-PJ-1
   (Wireframes und Texte), `03_contracts.md` für die API, `docs/UX_CONTRACT.md` U1–U12,
   `docs/FRONTEND_CONVENTIONS.md`.
3. `make -C frontend generate-schemas` ausführen, damit `projects`-Typen vorhanden sind
   (die Datei ist gitignored).
4. **Tests zuerst:** Vitest für die Zustände einer Quellenzeile (`reading`, `ready`,
   `failed` mit Grund); Playwright `@ci`: Projekt anlegen, Textquelle hinzufügen,
   fragen, die genannte Quelle sehen. Das Flag muss im Test an sein
   (`PROJECTS.ENABLED=1` oder `PROJECTS_ENABLED=1` für den Teststack).
5. **Umsetzen:** Abschnitt "Projekte" im Chats-Panel; Route `/projects/:id` mit Tabs
   Chats, Quellen, Instruktionen (Gedächtnis und Mitglieder kommen mit PJ20–PJ22);
   Dialog "Quelle hinzufügen" (Datei, Link, Text); Projektname im Composer eines
   Projekt-Chats; Chat über sein Menü in ein Projekt verschieben oder herauslösen
   (`PUT`/`DELETE /projects/{uuid}/chats/{chatId}`).
6. Die UI darf nur sichtbar sein, wenn das Flag an ist: die Projektrouten antworten
   sonst 404 `feature_not_configured`; die UI muss das als "nicht verfügbar" behandeln,
   nicht als Fehler.
7. Regeln aus `AGENTS.md` für die UI: `<script setup lang="ts">`, keine Komponente über
   300 Zeilen, kein `any`, keine Semikolons, Texte über `vue-i18n` in allen fünf
   Sprachen (`frontend/src/i18n/locales/{en,de,es,fr,tr}/`), Bestätigungen über
   `useDialog()`, Toasts über `useNotification()`, Farben nur als Tokens, Buttons mit
   House-Klassen, Heroicons. Hellen und dunklen Modus und Telefonbreite prüfen.
8. Vor dem Commit `make format` und `make ci-local` ungefiltert; vor dem Push
   `make test-e2e` (UI und Routen betroffen). Danach commit (`feat(projects): …`),
   push, `gh pr create` gegen `main`, auf Copilot-Review und `All Checks Passed`
   auf dem aktuellen Head warten, jeden Thread beantworten, **nicht mergen**.
9. Die Reise im Browser durchgehen (nicht nur Screenshot): anmelden mit
   `admin@synaplan.com` (Zugangsdaten in `docs/DEVELOPMENT.md`; der Admin hat ein echtes
   Default-Modell, der Demo-Account nicht), Projekt anlegen, Quelle hinzufügen, im
   Projekt-Chat nach einem Satz aus der Quelle fragen, Quelle entfernen, Projekt
   löschen. Das Flag dafür lokal einschalten und danach wieder auf `0` setzen.
10. `STATUS.md` im selben PR aktualisieren (Zeile PJ4, Eintrag mit Datum).

## 10. Anforderungen und Entscheidungen des Owners

- Antworten auf Deutsch, knapp, Ergebnis zuerst (der Owner liest oft auf dem Telefon).
- `AGENTS.md` ist verbindlich. Kein AI-Zusatz in Commits und PRs, solange `AGENTS.md`
  das fordert, nicht auf `main` pushen, nicht force-pushen auf `main`/`master`,
  Merge-Konflikte von Hand, nie `--ours`/`--theirs` auf Code.
- Sicherheit zuerst: nie einen Provider-Key oder ein Secret ausgeben, loggen oder
  committen. Beim Prüfen von Umgebungsvariablen keine Werte oder Präfixe ausgeben.
- Der Owner mergt. Ausnahme war eine ausdrückliche Anweisung für #3142, #3151 und
  #3152 (Admin-Override); sie gilt nicht weiter.
- Ob der Agent auf Copilot und CI warten soll, hat der Owner zuletzt mit "ich schaue
  selbst" beantwortet. Im Zweifel einmal kurz fragen.
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
  Migration freigegeben. Für PJ4 gibt es keine Schemaänderung; kommt eine, erst fragen.

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
- Dieses Dokument selbst ist nicht committet (siehe `git status`).
