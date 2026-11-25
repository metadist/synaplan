# HANDOFF — Projects and shared chats

Stand: 2026-11-25, `main` @ `9f95f9139` (PJ7a #3266 und Vision-Abrechnung #3267 gemergt). Geschrieben für einen Agenten ohne Zugriff
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
dem Kernel), **PJ5b** (die Übergabe im Request) und **PJ5c** (der Check vor der
Auslieferung) sind gemergt, ebenso **PJ6** (Kostenlimit und Kreiserkennung). PJ7 ist
geteilt: **PJ7a** (Chat-Suche und Dateien per ID) ist gemergt, als Nächstes kommt
**PJ7b** (Abschnitt 9).

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
| PJ5b Übergabe Request → Lauf, Tool-Katalog, G1 (Owner: reicht) | gemergt | #3218 |
| PJ5c Check vor der Auslieferung (03 §3.2) | gemergt | #3221 |
| PJ6 Kostenlimit (Anteil des Monatsbudgets, Nutzer wählt) und Kreiserkennung | gemergt | #3223 |
| PJ7a Chat-Suche und Dateien per ID (§0 #27 entschieden) | gemergt | #3266 |
| Vision-Aufrufe als `VISION` abgerechnet (Voraussetzung für Bildkosten im Lauf) | gemergt | #3267 |
| PJ7b Notizen, Verdichtung, `BRUNS.BNOTES`, "Weiter" | **nächster Schritt** | — |
| PJ8–PJ28 | nicht begonnen | — |

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

### PJ5a–PJ6 (Chat-Lauf, Flag aus)

Der Chat-Lauf (`App\Service\Chat\Loop`) läuft auf dem Kernel (PJ5a), ein Tool-Turn
wird aus dem Request übergeben und weiter gestreamt (PJ5b), ein Check prüft die
Antwort vor der Auslieferung (PJ5c), Kostenlimit und Kreiserkennung beenden ihn mit
einem Ergebnis (PJ6). Dateien siehe Abschnitt 4, Grenzen Abschnitt 6.

### PJ7a (#3266): Chat-Suche und Dateien per ID

§0 #27 (Owner, 2026-11-25): frühere Nachrichten sind über ein Tool erreichbar. Ein
Lauf bekommt vier lesende Tools über `ChatRunLookups`; `RegistryChatRunToolbox`
bietet sie an und verteilt die Aufrufe.

- `conversation_search` (`ChatRunConversationSearch`): `query` findet Nachrichten
  dieses Chats, die jedes Wort enthalten (höchstens 6 Wörter, 8 Treffer, neueste
  zuerst, Ausschnitt mit Message-ID, Autor, Zeit). `around` liest eine Nachricht mit
  drei davor und drei danach. Die eigene Frage und Antwort des Laufs
  (`incomingMessageId`, `replyMessageId`) sind ausgenommen.
- `list_files` (`ChatRunFileTools`): Dateien des Chats (dort erzeugt oder an eine
  Nachricht gehängt) und Quellen des Projekts, 20 pro Seite.
- `read_document`: Text per file_id in Teilen zu 8.000 Zeichen. Eine md/txt/csv/json
  ohne extrahierten Text (z. B. ein generierter Research-Bericht) wird von der Platte
  gelesen, höchstens 1 MB, nur über `catalog->absolutePath()` (Upload-Root-Prüfung).
- `analyze_image`: das Vision-Modell sieht ein gespeichertes Bild per file_id
  (`AnalyzeImageTool::analyseStored`); nur der Text geht ins Transkript. Nur
  angeboten, wenn die Person ein Vision-Modell hat. Höchstens 6 verschiedene Bilder
  pro Lauf (`ChatRunFileTools::MAX_PICTURES`, im State als `analyzed`, der Codec
  kappt eine gefälschte Liste).
- Queries in zwei neuen Repositories: `ChatFileRepository`,
  `ChatMessageSearchRepository` (`MessageRepository` ist eingefroren). Eine ID wird
  nur in diesem Chat dieser Person aufgelöst oder als Quelle des aktuellen Projekts;
  gelöschte Projektquellen erscheinen nicht. `%` und `_` sind in der Suche wörtlich.
- Prompt: Regel 6 in `tools:chat_loop` (`PromptCatalog`, Seeder upsertet).
- Walk im Browser (Admin, Claude Sonnet 5.5, `LOOP.ENABLED`=1 nur im Dev-Stack):
  Codewort per `conversation_search` gefunden, Bericht per `list_files` und
  `read_document` gelesen, Wort auf einem generierten Bild per `analyze_image`.

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
- **Seit PJ6** prüft ein `chat`-Lauf vor jedem Modellaufruf das Kostenlimit (Anteil
  des Monatsbudgets, den die Person in Einstellungen → Chat wählt: 5–100 %, Standard
  10 %) und das Restbudget, und endet mit einer Wrap-up-Antwort. Limit = das Kleinere
  aus Anteil und Restbudget; Reserve = geschätzter Wrap-up-Call (Transkript plus
  2.000 Token Antwort zum Modellpreis), mindestens 10 % des Limits; Budgetzeile bei
  jedem Arbeitsschritt; einmal "fast verbraucht" bei 80 %; reicht das Budget nicht
  für den Start, antwortet der Turn ohne Tools mit Hinweis (03 §1.3). Ohne Monatsbudget
  kein Limit pro Lauf; kein Token-Limit für unbepreiste Modelle (§0 #12, #14).
  Kreiserkennung 3/6/5: erst ein Hinweis, dann Wrap-up (§0 #15).
- **Ende eines Laufs** (§0 #68): ohne `update_plan` endet ein Turn mit einer normalen
  Antwort; mit offenen Plan-Punkten beendet das Pflicht-Tool `finish` den Lauf.

## 4. Betroffene Dateien für PJ5 (Orientierung)

- Vorhanden seit PJ5a: `backend/src/Service/Chat/Loop/*` (Profil `chat`, State, Codec,
  Einheit, Handler, Reap-Policy, Reaper, Starter, Reader, Auslieferung). Seit PJ5b:
  `ChatLoopGate` (wer auf den Loop darf), `ChatLoopTurn` (erster Aufruf, Übergabe),
  `ChatRunTail` (Stream folgt dem Lauf), `ConnectedToolCatalog`. Seit PJ5c:
  `ChatRunCheck` (Check vor der Auslieferung), `ChatRunSources`, `ChatRunCheckNote`,
  `ConfiguredChatRunCheckModel`; die Urteilsregeln teilt Research über
  `Service/Research/Run/Stage/CheckVerdicts`. Seit PJ6: `ChatRunCostLimit`
  (Anteil der Person, `BCONFIG` Owner = Person, `LOOP.RUN_COST_PERCENT`),
  `ChatRunCostGuard`, `ChatRunStallDetector`, `ChatRunWrapUp`, `ChatRunLoopLimits`;
  Einstellung `ChatRunLimitController` (`/api/v1/config/chat-run-limit`) und
  `frontend/src/components/settings/ChatRunLimitSetting.vue`. Seit PJ7a:
  `ChatRunLookups`, `ChatRunConversationSearch`, `ChatRunFileTools`,
  `Repository/ChatFileRepository`, `Repository/ChatMessageSearchRepository`.
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
- **PJ5b streamt:** der Lauf ruft das Modell streamend auf, `ChatRunSpeaker`
  schreibt den Text als `text.delta` in sein Log, der Stream reicht ihn als
  normale Chunks weiter. Gespeichert wird genau, was gezeigt wurde
  (`ChatRunState::$said`), danach die Antwort nur, wenn sie nicht gestreamt kam.
- **PJ5c (Owner, 2026-11-20):** der Check entfernt nichts aus der schon gestreamten
  Antwort; ein Hinweis darunter nennt die Aussagen, die die gelesenen Seiten nicht
  belegen. Das Prüfmodell läuft nur, wenn ein Admin `DEFAULTMODEL.VERIFY` (Owner 0)
  gesetzt hat und es nicht zur Familie des Chat-Modells gehört; höchstens 3 Aufrufe.
- **PJ6 (Owner, 2026-11-20):** das Kostenlimit setzt die Person, nicht der Admin,
  als Anteil ihres Monatsbudgets (5/10/20/25/50/100 %, Standard 10 %); nie mehr als
  das Budget. Kein Token-Limit für Modelle ohne Preis. Kreiserkennung 3/6/5.
- **PJ5b-Grenzen:** Wartet der Lauf oder endet das Folgen (240 s), zeigt
  die Nachricht den Platzhalter bis zur Auslieferung. Die SSE-Events `chat_run` sind
  für die Karte in PJ8. Stop mit "Text behalten" kann eine Nachricht mehr speichern
  (Stop gehört PJ8). Verbundene Tools (MCP, HTTP) nur über `connected_tools`
  (höchstens 8 offen, 60 gelistet).
- **G1 knapp, vom Owner akzeptiert (2026-11-20):** Median +10,1 % Kosten gegenüber
  dem Lauf ohne Loop am selben Tag (Tool-Deklarationen und Tool-Use-Preamble des
  Providers, etwa 1.300 Token). Prompt-Caching wäre der nächste Hebel.
- **PJ7a-Grenzen:** `analyze_image` zählt noch nicht zu den Kosten des Laufs. Seit
  #3267 schreibt `AiFacade::analyzeImage` eine `VISION`-Zeile und gibt
  `facade_charged_cost` zurück, aber `ChatRunFileTools::look()` reicht sie nicht an
  `costMicro` weiter (Abschnitt 8, Punkt 1). Bis dahin begrenzt nur `MAX_PICTURES`.
  Ein Lauf startet nur bei Providern mit `nativeToolRouting` (Anthropic, Groq);
  Gemini bleibt auf dem klassischen Pfad, Prompts wie "vergleiche …" gehen zu
  Deep Research. Zum Testen also ein Anthropic-Modell wählen.
- **Warten ohne `sleep`:** der `LongWaitGateTest` verbietet neue Sleeps. Ein Leser
  wartet blockierend auf den Stream `run:…:events:wake` (`RunKeys::awaitEvent`).
- **PJ5a-Grenzen:** der Lauf bietet keine Dokument-Tools, keine Skills und keinen
  Server-Code an (sie lesen ihren Kontext aus dem HTTP-Request). Gemini braucht die
  `thought_signature` der Tool-Aufrufe; sie wird im Transkript mitgeführt.
- **Offene §0-Zeilen** (nicht selbst entscheiden): vor PJ9 #31; vor PJ10 #20; vor PJ11 #65. PJ5 selbst hat keine offene Zeile.
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

1. **Bildkosten im Lauf** (klein, Folge aus #3266 und #3267): in
   `ChatRunFileTools::look()` `facade_charged_cost` des Vision-Aufrufs zu den Kosten
   des Laufs addieren, damit `ChatRunCostGuard` sie sieht. `analyseStored` muss dafür
   den Wert durchreichen. Test zuerst; `MAX_PICTURES` bleibt als zweite Grenze. Kann
   als eigener `fix:`-PR vor PJ7b oder als erster Commit von PJ7b kommen; den Owner
   fragen, wenn unklar.
2. **PJ7b** Notizen und Verdichtung (`RunNotes`, `TranscriptCompactor`,
   `notes.compacted`, `BRUNS.BNOTES` schreiben), Bild bearbeiten per file_id, dazu
   "Weiter" (`continueFrom`) aus PJ6. "Jetzt antworten" (`answer_now`) kommt mit PJ8.
3. **PJ8–PJ11** (Karte für den Lauf, Stop, Rückfragekarte usw.); offene Zeilen siehe
   Abschnitt 6. `LOOP.ENABLED` bleibt 0 bis PJ8 gemergt ist.
4. **Phase C (PJ12–PJ19)** gemeinsame Chats, noch nicht beauftragt.
5. **Phase D (PJ20–PJ22)**, **Phase E (PJ23–PJ27)**, **PJ28** Flags an.

## 9. Exakt nächster Schritt: PJ7b

PJ7a ist gemergt (#3266). Vorher oder als ersten Commit Punkt 1 aus Abschnitt 8
(Bildkosten). §0 #27 ist entschieden (Owner, 2026-11-25: Chat-Suche als
Tool, in PJ7a gebaut). Die Spalte `BRUNS.BNOTES` gibt es seit AH8a, keine Schemaänderung.
Brief: "PJ7b" in `04_sprints.md` (Notizen, Verdichtung) und
`03_contracts.md` §1.5. Dazu gehört "Weiter" nach einem Wrap-up (`continueFrom`,
03 §1.3): der neue Lauf startet aus `BRUNS.BNOTES` des alten, mit neuem Limit.

1. `git checkout main && git pull --ff-only`, neuer Branch.
2. **Tests zuerst**, dann `RunNotes` und `TranscriptCompactor` neben dem Lauf
   (`ChatRunStep` und `ChatRunAnswer` wachsen nicht über ihre Rolle hinaus;
   `MessageRepository` ist im Größen-Ledger eingefroren, die Suche kommt in eine neue
   Repository-Klasse).
3. Die Verdichtung darf IDs nicht verlieren: Message-IDs und file_ids, die der Lauf
   schon gesehen hat, bleiben in den Notizen, damit `conversation_search` (`around`)
   und `read_document` danach weiter funktionieren. `ChatRunState::analyzed` überlebt
   die Verdichtung (sonst lässt sich `MAX_PICTURES` umgehen).
4. Gate wie immer (`make ci-local`, E2E). `LOOP.ENABLED` bleibt 0 bis PJ8.
   Danach `STATUS.md` und diese Datei nachziehen.

### Worauf der nächste Agent achten soll

- **PR-Zustand vor jedem Push prüfen** (`gh pr view <n> --json state`). PJ7a wurde
  gemergt, während noch an dem Branch gearbeitet wurde; nach dem Merge immer neuer
  Branch von `main`.
- **Sicherheit der Lookups:** jede neue Abfrage per ID geht über `ChatFileRepository`
  oder `ChatMessageSearchRepository` mit Person, Chat und Projekt-Ordner. Nie eine
  Datei oder Nachricht nur per ID laden. Integrationstest für "fremder Chat / fremde
  Person liefert nichts" mitschreiben (`ChatRunLookupRepositoryTest` als Vorbild).
- **Dateizugriff nur über `catalog->absolutePath()`**, nie einen Pfad aus Tool-Args
  bauen. Bilddaten und Rohdateien kommen nie ins Transkript.
- **Tests mit Laufdaten im Browser:** Admin aus `docs/DEVELOPMENT.md`, ein
  Anthropic-Modell, `LOOP.ENABLED`=1 nur in der Dev-DB. Den Zustand eines Laufs zeigt
  Redis `synaplan:dev:run:state:<uuid>` (JSON mit messages, tool_calls, analyzed).
- **Lokale E2E:** etwa 38–53 Fehler sind Umgebung (Stripe, Mail, Gast, Notion);
  immer gegen `main` vergleichen. `chat.spec.ts` kann unter 4 Workern flaky sein,
  dann seriell gegenprüfen. Ein Mutagen-Konflikt in einem alten Worktree unter
  `.worktrees/` kann Dateien im Container kurz entfernen und das Backend abstürzen
  lassen; dann Sync prüfen, Stack neu starten.
- **Nicht committen:** `_devextras/security-audit/` (fremd, ungetrackt).

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
