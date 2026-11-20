# HANDOFF — Projects and shared chats

Stand: 2026-11-20, `main` @ `23faeb218` (PJ5a gemergt), PJ5b auf `feat/chat-loop-handover`. Geschrieben für einen Agenten ohne Zugriff
auf den bisherigen Chat. Jede Aussage wurde gegen `main` und die Planungsdateien
geprüft. Was nicht geprüft wurde, steht unter "Nicht verifiziert".

Lies zuerst: `/AGENTS.md` (die Schleife und das Gate), danach in diesem Ordner
`STATUS.md` (Schrittprotokoll), `04_sprints.md` (die Briefs PJ0–PJ28),
`00_master_plan.md` §0 (Entscheidungstabelle, ☐ = offen) und `03_contracts.md`
(§1.1 ist der Vertrag für PJ5). Für den Kernel:
`../20261023-agent-handling/STATUS.md` (AH8a, AH8b). Für den Loop:
`../20261021-architecture-update/STATUS.md` (AU26).

## 1. Ziel und aktuelle Aufgabe

**Ziel (Planungsordner):** Projekte (Quellen und Instruktionen für mehrere Chats) und
gemeinsame Chats für Synaplan, dazu ein Chat, der als Lauf ("Loop", mehrere Schritte)
beliebig lange arbeiten darf. 28 Schritte PJ0–PJ28 in Phasen 0, A–F.

**Auftrag des Owners:** die Schritte der Reihe nach umsetzen, nach `AGENTS.md`,
mit Blick auf Usability und vor allem Sicherheit.

**Stand:** Phase 0 und A (PJ0–PJ4) sind fertig. Die Voraussetzungen für Phase B
(AU26, AH8a, AH8b) sind gemergt. Der Owner hat PJ5 geteilt: **PJ5a** (der Lauf auf
dem Kernel) ist gemergt, **PJ5b** (die Übergabe im Request) liegt als PR vor,
**PJ5c** (der Check vor der Auslieferung) ist der nächste Schritt (Abschnitt 9).

| Schritt | Zustand | PR |
| ------- | ------- | -- |
| PJ0 Plan | gemergt | #3133 |
| PJ1 Corpus und Runner | gemergt | #3141 |
| PJ2 Spike "ein Chat-Turn als Run-Einheiten" | gemergt | #3142 |
| Loop-Vertrag (Ledger, Guard, Ende eines Laufs) | gemergt (Doku) | #3151 |
| PJ3 Projekte mit Quellen und Instruktionen (API) | gemergt | #3152 |
| PJ4 Projektseite, Projekte im Chats-Panel | gemergt | #3186 |
| Plan nach PJ4, Reihenfolge AU26 → AH8 | gemergt (Doku) | #3191 |
| AU26 ein Agent-Loop (Zeile 22, S4) | gemergt | #3193 |
| AH8a Run-Kernel und `BRUNS` (Zeile 25) | gemergt | #3198 |
| AH8b Research läuft auf dem Kernel | gemergt | #3201 |
| PJ5a Chat-Lauf auf dem Kernel (Flag aus) | gemergt | #3216 |
| PJ5b Übergabe Request → Lauf, Tool-Katalog, G1 (Owner: reicht) | PR offen | `feat/chat-loop-handover` |
| PJ5c Check vor der Auslieferung (03 §3.2) | **nächster Schritt** | — |
| PJ6–PJ28 | nicht begonnen | — |

## 2. Bisherige Implementierungen

### PJ0–PJ2 (Plan, Messung)

- **PJ0:** Plan in diesem Ordner (`00`–`04`, `STATUS.md`).
- **PJ1:** `backend/tests/Eval/ChatLoop/` mit `corpus.json` (40 Turns: 10 simple,
  8 search, 8 files, 8 multi_step, 6 research), vier synthetischen Dokumenten, drei
  geskripteten Chats und `run.php`. `StackClient.php` meldet sich wie der Web-Client
  an, sendet Turns an `POST /api/v1/messages/stream` und liest Run-Daten. Die
  Vergleichszahlen stehen in `STATUS.md` (z. B. `simple`: 0.0301 Kosten, erstes
  Token 2,56 s, Total 3,64 s; Basis für G1, den Exit von PJ5).
- **PJ2:** "Jeder Turn über die Pump-Queue" verletzt G1 unter Last (16 gleichzeitige
  Turns: Faktor 30 beim ersten Text). **§0 #21 gilt:** ein einfacher Turn läuft im
  Request, erst ein Turn mit Tool-Aufruf geht in die Queue (die Übergabe von PJ5).
  Skripte unter `spike/` (kein Produktcode).

### PJ3 (Projekte-API, Backend)

Migration `backend/migrations/Version20261117120000.php` (rohes idempotentes SQL, keine
Fremdschlüssel):

- Tabelle `BPROJECTS`: `BID`, `BUUID` (CHAR 36, unique), `BUSERID`, `BNAME` (120),
  `BDESCRIPTION` (500, null), `BINSTRUCTIONS` (TEXT, null), `BGROUPKEY` (64),
  `BCREATED`, `BUPDATED`; Index `idx_project_user`.
- `BCHATS` neu: `BPROJECTID` (BIGINT null), `BPROJECTSHARED` (TINYINT, default 0),
  `BMEMORYMODE` (VARCHAR 20 null), Index `idx_chat_project`.
- `BCONFIG` (`BOWNERID=0`, Gruppe `PROJECTS`, Setting `ENABLED`) = `'0'`.

Neue Dateien unter `backend/src/`:

| Datei | Aufgabe |
| ----- | ------- |
| `Entity/Project.php`, `Repository/ProjectRepository.php` | Entity (`datetime_immutable`) und Queries |
| `Controller/ProjectController.php` | 13 Routen unter `/api/v1/projects`; nur HTTP |
| `Module/Chat/ProjectsModule.php` | FeatureModule; Env `PROJECTS_ENABLED`, BCONFIG `PROJECTS.ENABLED` |
| `Service/Project/ProjectsConfig.php` | Schalter und Grenzen |
| `Service/Project/ProjectService.php` | Anlegen, Ändern, Löschen (`chats=keep\|delete`) |
| `Service/Project/ProjectSourceService.php`, `ProjectSourceIndexer.php` | Quellen Datei/Link/Text; Rate-Limit und Quote **vor** dem Abruf |
| `Service/Project/ProjectChatScope.php` | liefert für einen Chat den Pin (`ProjectTurnContext`) oder null |
| `Service/Project/ProjectChatMembership.php` | Chat aufnehmen oder entfernen |
| `Service/Iam/Erasure/ProjectEraser.php` | Account-Löschung entfernt die Projekte |

Wichtige Eingriffe in Bestehendes: `StreamTurnPreparer::withProject` (Pin vom Server),
`ChatSystemPromptAssembler` / `ChatPromptBlocks::projectInstructions`,
`File::SOURCES` += `project_file`, `project_link`, `project_text`,
`ModuleGateSeeder` (`projects` in `DEFAULT_ON`), `nelmio_api_doc.yaml` (Schemas
`Project`, `ProjectSource`, `ProjectChat`).

`BPROJECTSHARED` und `BMEMORYMODE` existieren, werden aber nirgends gelesen oder
gesetzt (reserviert für PJ20–PJ22).

### PJ4 (Frontend)

`views/ProjectView.vue` (`/projects/:id`, Tabs Chats, Quellen, Instruktionen),
`components/projects/*`, `stores/projects.ts`, `services/api/projectsApi.ts`;
Abschnitt "Projekte" im Chats-Panel, Dialog "Quelle hinzufügen", Projektname über dem
Composer, Chat ins Projekt und heraus über das Chat-Menü, angepinnte Projekte zuerst
(`BPROJECTS.BPINNEDAT`, Migration `Version20261119120000`). Flag aus → 404
`feature_not_configured`, die UI zeigt nichts. `PROJECTS_ENABLED=1` steht in
`backend/.env.test` für die E2E-Spec.

### AU26 (#3193): ein Agent-Loop

- `App\Service\Tool\Loop\AgentToolLoop` besitzt Iterationsgrenze, Deadline und Tools
  pro Turn und liest sie aus `ToolLoopLimits`, die der Aufrufer übergibt.
  `ToolLoopLimits::defaults($cap)` = heutige Zahlen aus `ToolLimits`;
  **`ToolLoopLimits::unbounded()`** = keine Grenze, keine Deadline → **Eingabe des
  Profils `chat` in PJ5**.
- Ein Turn endet als `stop`, `again` (ein weiterer Aufruf ohne Tool, Web-Search-Wrap-up)
  oder `run`. `ToolCallPartition` trennt eigene von Client-Tools. Ergebnis:
  `ToolLoopRun` mit `ToolLoopStop` und Iterationszahl.
- Adapter (`ToolLoopAdapter`): `DocumentToolLoopAdapter`, `MessagesCompleteAdapter`,
  `MessagesStreamAdapter`, `OpenAiToolLoopAdapter`. `GatewayToolLoop` 1 274 → 223
  Zeilen, aus dem Size-Ledger. Snapshot `tool_loops.json` unverändert.
- Fehler als Ergebnis bleiben in `ToolInvoker` und den Executoren (AU24): der Loop
  sieht nie eine Tool-Exception.

### AH8a (#3198): Run-Kernel und `BRUNS`

- `App\Service\Run\*`: `RunService` (Start mit 24-h-`Idempotency-Key`, Stop als Flag),
  `RunUnit` (claim, reload, Stop prüfen, ausführen, gefencter Checkpoint, nächste
  Einheit), `RunClaim`, `RunCheckpointStore`, `RunEventLog`, `RunEventStream`,
  `RunReaper`, `RunDelivery` (einmal in den Chat, auf dem Server), `RunKeyRemoval`,
  `RunUnitBus`.
- Pro Art verschieden: `RunState` / `RunStateCodec`, `RunUnitHandler`,
  `RunReapPolicy`. Budgets sind nicht im Kernel; der Handler bekommt sie als
  Profil-Eingabe.
- Migration `Version20261119150000`: `BRUNS` nach `../20261023-agent-handling/03_contracts.md`
  §2.1 plus `BNOTES` (mediumtext null); `BKIND` ∈ `assistant`, `research`, `chat`.
  Kein Unique-Index auf `(BUSERID, BIDEMPOTENCY)` (Redis hält das 24-h-Fenster).
- `RunEraser` (Account-Löschung), `app:runs:reap` führt alle Services mit Tag
  `app.run_reaper` aus.
- **Für PJ5 noch nicht vorhanden:** Messenger-Transport für `RunUnitBus` und
  `app:runs:prune` ("kommen mit dem ersten Konsumenten", AH8a). Research nutzt seinen
  eigenen Worker. Siehe Abschnitt 6 (ask-first prüfen).

### AH8b (#3201): Research auf dem Kernel

- `ResearchRunState` implementiert `RunState`, `ResearchRunCodec` liest ihn.
  `ResearchStateStore` ist eine Fassade über einem `RunCheckpointStore` mit dem
  Research-Keyspace (`RunKeyspace('research', …, ['state' => 'run', 'cancel' =>
  'stop'])`); alle Redis-Schlüssel behalten ihren Namen.
- `ResearchUnitWorker` → `RunUnit` mit `ResearchUnitHandler`; `ResearchReaper` →
  `RunReaper` mit `ResearchReapPolicy`. `ResearchLease` ist weg (Stufen halten
  `RunClaim`). Doppeltes Lua entfernt.
- Der Kernel lernte: umbenannte Key-Segmente, gleitender Ablauf während ein Lauf aktiv
  ist (`activeTtlHours`), einen Hook nach jedem Schreiben (`written`), einen Codec, der
  "kein Lauf" (null) antworten darf.
- `services.yaml`: `App\Service\Run\RunKeys: '@App\Service\Run\RedisRunKeys'` (zwei
  Implementierungen; ohne Alias bricht das Autowiring).
- **Akzeptierte Kante beim Deploy:** die Reap-Marke wandert von
  `research:reap:{id}:{seq}` nach `research:reap:{id}`; eine Einheit, die in den
  Minuten um den Deploy verloren geht, kann einmal öfter neu dispatcht werden.
- Gate: `make ci-local` (PHPUnit 12 462, Vitest 539 Dateien, PHPStan und Deptrac 0),
  Research-E2E grün. Volle E2E lokal: 50 Fehler auf dem Branch, 53 auf `main`
  (Umgebung, siehe Abschnitt 6). Copilot konnte #3201 wegen erschöpfter Quote nicht
  reviewen; der Owner hat trotzdem gemergt.

## 3. Wichtige Architektur- und Designentscheidungen

- **Quellen sind normale Dateien** (`BFILES`) unter dem Wissensordner des Projekts
  (`BPROJECTS.BGROUPKEY`, Form `project:<uuid>`). Keine Quelltabelle.
- **Der Ordner-Pin kommt vom Server** (`StreamTurnPreparer::withProject`), jeder
  `ragGroupKey` des Requests wird überschrieben. PJ5 muss den Pin **pro Einheit neu
  prüfen** (Chat kann zwischen zwei Einheiten aus dem Projekt verschoben werden).
- **Flag aus = 404 und Plain-Chat**; der Controller prüft das Flag zusätzlich zum
  `ModuleGateListener`.
- **Ein Chat tritt über die Projekt-Routen bei** (`POST/PUT/DELETE
  /projects/{uuid}/chats…`), nicht über `/chats` (Größe von `ChatController`).
- **Einfacher Turn im Request, Tool-Turn als Lauf** (§0 #21, PJ2-Messung).
- **Grenzen als Profil-Eingabe** (§0 #69): kein Limit im Kernel oder Loop selbst.
- **Kein festes Zeit- oder Schrittlimit** für den Chat-Lauf; einzige Leitplanken sind
  ein Kostenlimit und Kreiserkennung (PJ6), beide enden mit einem Ergebnis.
- **Bis PJ6** prüft ein `chat`-Lauf vor jedem Modellaufruf das Monatsbudget des
  Zahlenden und endet sauber, wenn es leer ist (§0 #70).
- **Ende eines Laufs** (§0 #68): ohne `update_plan` endet ein Turn mit einer normalen
  Antwort; mit offenen Plan-Punkten beendet das Pflicht-Tool `finish` den Lauf.

## 4. Betroffene Dateien für PJ5 (Orientierung)

- Vorhanden seit PJ5a: `backend/src/Service/Chat/Loop/*` (Profil `chat`, State, Codec,
  Einheit, Handler, Reap-Policy, Reaper, Starter, Reader, Auslieferung). Seit PJ5b:
  `ChatLoopGate` (wer auf den Loop darf), `ChatLoopTurn` (erster Aufruf, Übergabe),
  `ChatRunTail` (Stream folgt dem Lauf), `ConnectedToolCatalog`. PJ5c ergänzt den
  Check vor der Auslieferung in `ChatRunDelivery`.
- Lesen: `ChatStreamOrchestrator`, `MessageProcessor`, `TurnDispatcher`, `ChatHandler`,
  `ToolRegistry`, `PromptCatalog`, `Service/Chat/Run/*` (heutiger re-attachbarer
  Chat-Turn: `ChatRunService`, `RunStream`, `PumpChatRunner`),
  `Service/Tool/Loop/*`, `Service/Run/*`, `Service/Research/Run/*` als Vorbild.
- **Nicht wachsen lassen:** `MessageProcessor`, `TurnDispatcher` (Size-Ledger).
- Prompt `tools:chat_loop` (interne Topics beginnen mit `tools:`; Katalogdaten als
  idempotenter Seeder).
- Characterization-Snapshots (`backend/tests/Characterization/`) müssen mit Flag aus
  unverändert bleiben.

## 5. Technische Details, APIs, Datenbank

**Projekt-Routen** (alle `/api/v1/projects`, Cookie-Login, Flag an):

| Methode und Pfad | Zweck |
| ---------------- | ----- |
| `GET /`, `POST /` | Liste; anlegen `{name, description?}` (201) |
| `GET/PATCH/DELETE /{uuid}` | lesen; `name`, `description`, `instructions`; löschen `?chats=keep\|delete` |
| `GET/POST /{uuid}/sources` | Quellen; Datei (multipart `files[]`), `{type:"link",url}`, `{type:"text",title,text}` |
| `POST /{uuid}/sources/{fileId}/refresh`, `DELETE /{uuid}/sources/{fileId}` | Link neu lesen; entfernen |
| `GET/POST /{uuid}/chats`, `PUT/DELETE /{uuid}/chats/{chatId}` | Chats; neu anlegen; aufnehmen; herauslösen |

Fehlercodes: 401, 404 (`feature_not_configured`), 409 (Limit/Speicher), 422, 429.

**Grenzen** (`PROJECTS`, Owner 0): `MAX_SOURCES` 50, `INSTRUCTIONS_MAX_CHARS` 4000.

**Schalter:**

- `PROJECTS.ENABLED` = 0 (bis PJ28, §0 #73); Env `PROJECTS_ENABLED` gewinnt, wenn gesetzt.
- `LOOP.ENABLED` = 0 (Migration `Version20261120090000`, PJ5a); Env `LOOP_ENABLED`
  gewinnt, wenn gesetzt (`ChatLoopConfig`). Er bleibt 0 in jeder Umgebung, bis PJ6
  und PJ8 gemergt sind (§0 #70).

**Zod:** nach jeder OpenAPI-Änderung `make -C frontend generate-schemas`, dann
`vue-tsc`. Keine handgeschriebenen Payload-Interfaces.

## 6. Bekannte Probleme, Einschränkungen, worauf zu achten ist

- **Transport (gelöst in PJ5a):** `RunChatUnit` und `ResumeChatRunCommand` laufen
  auf `async_ai_high` im bestehenden Worker; nur `messenger.yaml`, kein Container.
  Eine Schemaänderung bleibt Ask-first.
- **PJ5b-Grenzen:** gespeichert wird nur die Antwort des Laufs, eine im ersten Aufruf
  gestreamte Einleitung nicht. Wartet der Lauf oder endet das Folgen (240 s), zeigt
  die Nachricht den Platzhalter bis zur Auslieferung. Die SSE-Events `chat_run` sind
  für die Karte in PJ8. Stop mit "Text behalten" kann eine Nachricht mehr speichern
  (Stop gehört PJ8). Verbundene Tools (MCP, HTTP) nur über `connected_tools`
  (höchstens 8 offen, 60 gelistet).
- **G1 knapp, vom Owner akzeptiert (2026-11-20):** Median +10,1 % Kosten gegenüber
  dem Lauf ohne Loop am selben Tag (Tool-Deklarationen und Tool-Use-Preamble des
  Providers, etwa 1.300 Token). Prompt-Caching wäre der nächste Hebel.
- **Warten ohne `sleep`:** der `LongWaitGateTest` verbietet neue Sleeps. Ein Leser
  wartet blockierend auf den Stream `run:…:events:wake` (`RunKeys::awaitEvent`).
- **PJ5a-Grenzen:** der Lauf bietet keine Dokument-Tools, keine Skills und keinen
  Server-Code an (sie lesen ihren Kontext aus dem HTTP-Request). Gemini braucht die
  `thought_signature` der Tool-Aufrufe; sie wird im Transkript mitgeführt.
- **Offene §0-Zeilen** (nicht selbst entscheiden): vor PJ6 #12 (Kostenlimit 5.00),
  #14 (Token-Cap für unbepreiste Modelle), #15 (Kreiserkennung 3/6/5); vor PJ7 #27;
  vor PJ9 #31; vor PJ10 #20; vor PJ11 #65. PJ5 selbst hat keine offene Zeile.
- **Lokale E2E:** etwa 50–53 Tests schlagen auf diesem Stack auch auf `main` fehl
  (Umgebung). Vergleich immer gegen `main`; bekannte Fälle in `docs/E2E_TESTING.md`.
- **Veralteter Stack nach Codeänderung:** `backend worker scheduler pump` neu starten
  und auf `/api/health` = 200 warten, bevor E2E läuft.
- **php-cs-fixer im Container hält auf dem Mac nicht** (einseitiger Datei-Sync);
  Fixes lokal anwenden, Import-Reihenfolge notfalls von Hand.
- **Snapshots** neu schreiben mit `UPDATE_ROUTING_SNAPSHOTS=1`,
  `UPDATE_CLEANUP_V2_SNAPSHOTS=1` oder `UPDATE_FEATURE_STATUS_SNAPSHOTS=1`. Im Container
  geschriebene Dateien gehören `root`; mit `docker compose exec -T -u root backend cat …`
  lesen und lokal anlegen. Nie mit leerer `cat >`-Ausgabe überschreiben.
- **Copilot-Quote:** bei #3201 erschöpft. Wenn Copilot nicht reviewen kann, dem Owner
  sagen und nicht selbst mergen.
- **Nicht verifiziert:** PDF-Quelle per multipart im echten Chat (nur Textquelle per
  Hand); PJ2-Prototyp hat G7 nicht erfüllt (Lauf im Worker mit Lease und Neustart steht
  aus, siehe `STATUS.md`).

## 7. Getestete und verworfene Ansätze

- `ProjectSchemas`-DTO mit `#[OA\Schema]` wurde nicht gescannt → Schemas in
  `nelmio_api_doc.yaml`.
- Flag nur über `ModuleGateListener` reichte nicht → zusätzlich im Controller.
- `File::setSource` fiel auf `web_upload` zurück → Projektquellen in `File::SOURCES`.
- Datumsspalten `datetime` → Schema-Drift → `datetime_immutable`.
- Beitritt über `POST /chats` / `PATCH /chats/{id}` verworfen (Größe `ChatController`).
- "Jeder Turn über die Pump-Queue" verworfen (G1 unter Last).
- AH8b: zwei `RunKeys`-Implementierungen ohne Alias → Autowiring-Fehler → Alias in
  `services.yaml`.
- Demo-Account (`demo@synaplan.com`) hat kein Default-Modell → nur Demo-Texte; für echte
  Antworten den Admin-Account aus `docs/DEVELOPMENT.md` nehmen.

## 8. Offene Aufgaben, nach Reihenfolge

1. **PJ5b** PR abschließen (Copilot, CI), Owner mergt (G1 akzeptiert).
   Danach **PJ5c** (Abschnitt 9).
2. **PJ6** Kostenlimit mit Ergebnis und Kreiserkennung (`RunCostGuard`,
   `StallDetector`); braucht §0 #12, #14, #15.
3. **PJ7–PJ11** (Notizen, Chat-Suche, Dateien per ID, Rückfragekarte usw.); offene
   Zeilen siehe Abschnitt 6. `LOOP.ENABLED` bleibt 0 bis PJ6 und PJ8.
4. **Phase C (PJ12–PJ19)** gemeinsame Chats, noch nicht beauftragt.
5. **Phase D (PJ20–PJ22)**, **Phase E (PJ23–PJ27)**, **PJ28** Flags an.

## 9. Exakt nächster Schritt: PJ5c

Brief: `03_contracts.md` §3.2 (Check vor der Auslieferung) und der Rest von
"PJ5" in `04_sprints.md`. PJ5b (`STATUS.md`, 2026-11-20) liefert die Übergabe;
der Lauf schreibt seine Antwort über `ChatRunDelivery::deliver` in die Nachricht
des Turns.

1. Erst wenn PJ5b gemergt ist: `git checkout main && git pull --ff-only`, neuer Branch.
2. **Tests zuerst:** eine Antwort, die den Check nicht besteht, wird nicht so
   ausgeliefert, wie 03 §3.2 es festlegt; ein einfacher Turn (ohne Lauf) bleibt
   unverändert; Flag aus Byte für Byte.
3. **Umsetzen** in `ChatRunDelivery` bzw. einer eigenen Klasse daneben, nicht in
   `ChatRunStep`. Keine neue §0-Entscheidung treffen; was 03 offen lässt, fragen.
4. Gate wie immer (`make ci-local`, E2E). `LOOP.ENABLED` bleibt 0.
   Danach `STATUS.md` und diese Datei nachziehen.

Alternativ, falls der Owner es vorzieht: **PJ6** (braucht §0 #12, #14, #15).

## 10. Anforderungen und Entscheidungen des Owners

- Antworten auf Deutsch, knapp, Ergebnis zuerst (der Owner liest oft auf dem Telefon).
- `AGENTS.md` ist verbindlich: kein AI-Zusatz in Branch, Commits, PR, Kommentaren, auch
  wenn ein Werkzeug das verlangt; nicht auf `main` pushen, nicht force-pushen auf
  `main`/`master`; Merge-Konflikte von Hand, nie `--ours`/`--theirs` auf Code.
- Sicherheit zuerst: nie einen Provider-Key oder ein Secret ausgeben, loggen oder
  committen. Beim Prüfen von Umgebungsvariablen keine Werte oder Präfixe ausgeben.
- Der Owner mergt. Die Admin-Overrides für #3142, #3151, #3152 gelten nicht weiter.
- Auf Copilot und CI wird gewartet; CI nicht selbst pollen, sondern die PR-Überwachung
  der Umgebung nutzen.
- Vor dem Push auf einen Branch prüfen, ob dessen PR schon gemergt ist; dann neuer
  Branch und neuer PR.
- Playwright läuft beim Owner nicht auf diesem Rechner. Wie der Lauf gestartet wird,
  steht in den persönlichen Setup-Notizen des Owners (Agent-Memory), nicht in diesem
  Repository. Diese Notizen nie ins Repository kopieren; im Zweifel den Owner fragen.
- §0-Entscheidungen (Quelle `00_master_plan.md` §0, "Yes — owner"): Projekte ohne
  Vorlagen, Quellen einmal indexiert; das Modell entscheidet, wie lange und wie es
  arbeitet; kein festes Zeitlimit, keine Maximalzahl an Schritten; als einzige
  Leitplanken Kostenlimit und Kreiserkennung, beide mit Ergebnis; Web-Chat und
  Projekte, nicht das Widget; das Chat-Modell für alles; Notizen mit sichtbarem
  "Conversation summarized"; Dateien per ID im ganzen Chat; Rückfragekarte mit Optionen
  und freiem Text; gemeinsame Chats mit mehreren Schreibenden; keine persönlichen
  Erinnerungen in gemeinsamen Chats; Apps standardmäßig aus; Push aufs Telefon.
  Freigegeben am 2026-11-19: #21–#25, #63, #66, #68–#73. Offene ☐-Zeilen **nicht**
  selbst entscheiden.

## 11. Lokale Umgebung

- Docker-Stack: `backend`, `frontend`, `db`, `worker`, `scheduler`, `pump`,
  `centrifugo` u. a. App `http://localhost:5173`, API `http://localhost:8000`.
  Datenbank-CLI im Container `db` heißt `mariadb`.
- `PROJECTS.ENABLED` steht im Dev-Stack auf `0`. Zum Testen:
  `docker compose exec -T backend php bin/console dbal:run-sql "UPDATE BCONFIG SET BVALUE='1' WHERE BGROUP='PROJECTS' AND BSETTING='ENABLED'"`,
  danach wieder `'0'`.
- Unbeschreibbarer `var/cache/test`: `docker compose exec -T backend rm -rf var/cache/test`.
