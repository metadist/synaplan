# Handoff: Synaplan Security-Audit und Fixes

Stand: 2026-11-25, abends. `main` @ `12578c55f`, letzter Tag `v5.10.3`.
Der Ordner `_devextras/security-audit/` liegt seit dem 26.11.2026 auf
Entscheidung des Owners vollständig im Repo.

## 1. Worum es geht

Defensives Audit von `metadist/synaplan` (Repo + lokaler Docker-Stack). Ergebnis
ist `_devextras/security-audit/REPORT.md` mit 43 Findings (SYN-01 bis SYN-43):
Tabelle in Abschnitt 3, Details mit PoC und Fix-Vorschlag in Abschnitt 4.
SYN-44 kam beim Fixen dazu und steht nicht im Report. Die Fixes laufen über
GitHub Security Advisories mit temporären privaten Forks (Ablauf in
Abschnitt 5).

## 2. Was erledigt ist

29 Advisories im Repo: 25 veröffentlicht, 3 Drafts (SYN-14, SYN-23, SYN-25),
1 als Duplikat geschlossen (GHSA-2xmp-mxfh-h3f2). Von den 25 gehören 21 zu diesem Audit
(Tabelle unten, alle ohne CVE); vier sind ältere, davon unabhängige
Advisories (GHSA-26f3-684h-hpjh, GHSA-2x6x-8r52-pm58, GHSA-phrw-3wm4-3xcx,
GHSA-qqg9-qv48-j8m8).

| Finding | Advisory | Gepatcht in | Status |
| --- | --- | --- | --- |
| SYN-01 Inbound-E-Mail-Webhook ohne Auth | GHSA-23qf-8867-9pxv | 5.9.0 | published |
| SYN-02 Widget-Besucher handelt als Owner | GHSA-23w3-33g7-6p48 | 5.9.0 | published |
| SYN-03 Synaform Pfad: File read/delete | GHSA-c77r-gx6r-f4j4 | 5.10.0 | published |
| SYN-04 Apple Sign-in per unsignierter E-Mail | GHSA-pcm8-44x4-fhq7 | 5.10.0 | published |
| SYN-05 + SYN-06 SsrfGuard fail-open, Redirects | GHSA-6jp8-jcxm-96rx | 5.10.1 | published |
| SYN-07 Synaform URL-Quellen SSRF | GHSA-6h69-j26q-v29m | PR #3226 | published, "patched version" leer |
| SYN-08 WhatsApp-Webhook ohne Signatur | GHSA-v335-3cxj-mwcm | 5.10.1 | published |
| SYN-09 GET-Routen starten Turns | GHSA-hv6g-8mc6-9833 | 5.10.2 | published 25.11. |
| SYN-10 Message-Status fremder Nutzer | GHSA-qh57-5q43-qr3m | 5.10.2 | published 25.11. |
| SYN-12 SVG als `.heic` | GHSA-rf7q-2752-gwp3 | 5.10.2 | published 25.11., als "high" |
| SYN-13 Gast-Dateien | GHSA-qw62-wpg3-xg58 | 5.10.2 | published 25.11. |
| SYN-15 Legacy-`promptUpdate` | GHSA-q46m-xjxw-mpjw | 5.10.2 | published 25.11. |
| SYN-20 Mailbox "Test connection" | GHSA-wcp8-gvfp-x86q | 5.10.2 | published 25.11. |
| SYN-21 Widget-Besucher-ID | GHSA-8mxv-f2fx-v233 | 5.10.2 | published 25.11. |
| Rest SYN-06 Redirect-Fetches | GHSA-9fxp-p6f4-hfgf | 5.10.2 | published 25.11. |
| extern: DNS-Pin fail-open | GHSA-6wf3-v7mv-hv5p | 5.10.2 | published 25.11. |
| extern: Telefon-Verifizierung | GHSA-wqjm-ghfp-p68x | 5.10.2 | published 25.11. |
| SYN-18 Pre-Registration-Hijack | GHSA-55fj-g7qr-f53h | 5.10.3 | published 25.11. |
| SYN-19 OAuth-Callbacks ohne Browser-Bindung | GHSA-wfc5-m96h-36c2 | 5.10.3 | published 25.11. |
| SYN-22 MCP-Tools ohne Hinweise (eingegrenzt) | GHSA-qgpg-cx8m-7mhf | 5.10.3 | published 25.11. |
| SYN-44 Login-Adresse ohne Bestätigung änderbar | GHSA-qr28-f4ww-hrg9 | 5.10.3 | published 25.11. |
| SYN-14 Besucher-Uploads im geteilten RAG-Index | GHSA-6m8j-g99j-hg44 | **noch kein Release** | **Draft**, Fix in `main` (`fa202d4cf`) |

Teilweise erledigt: SYN-17 (Forgot-Password hat Human-Check und Limits, PR
#3260, normaler öffentlicher PR ohne Advisory). Offen davon siehe 3b.

**Was am 25.11.2026 passiert ist**

- SYN-18, SYN-44, SYN-19 (Web und native App) und SYN-22 gebaut, je als PR im
  privaten Fork. Der Owner hat alle vier über die Advisory-Seiten gemergt
  (`0718f380e`, `d8b16fc03`, `7ea4b975e`, `98920f221`).
- `make ci-local` auf dem Stand mit allen vier Fixes zusammen: grün (`ci=0`,
  Vitest 3818 in 551 Dateien, PHPStan/deptrac ohne Befund). Playwright lief
  auf dem kombinierten Stand lokal nicht, nur pro Fix einzeln.
- Der Owner hat `v5.10.3` geschnitten. Auf sein ausdrückliches Wort sind 14
  Advisories veröffentlicht; vorher je Fix geprüft, dass der Merge-Commit im
  genannten Tag liegt (`git tag --contains`). Bei SYN-18, 19, 44 den
  betroffenen Bereich auf `<= 5.10.2` korrigiert.
- Text von GHSA-8mxv (SYN-21) vor dem Veröffentlichen korrigiert: er nennt
  jetzt den Bestandsschutz für Widgets, die schon vorher eine
  `{externalUserId}`-URL abriefen.
- WordPress-Plugin gepusht, PR offen:
  https://github.com/metadist/synaplan-ai-support-chat/pull/11 (Version
  1.0.11, Owner mergt und veröffentlicht im WordPress-Verzeichnis).
- Der Owner hat danach den SYN-14-PR gemergt (`fa202d4cf`, nach dem Tag
  `v5.10.3`).

**Was am Abend des 25.11.2026 dazukam (Sitzung 2)**

- SYN-23 gebaut: Draft GHSA-rmmx-x383-4p77, PR im privaten Fork, wartet auf
  den Owner.
- SYN-24 geprüft: die SSRF ist seit 5.10.2 zu. Nur die Prüfung beim Speichern
  fehlte, die liegt als öffentlicher PR #3273 vor (kein Advisory nötig, weil
  nichts Unveröffentlichtes verraten wird).
- SYN-25 gebaut: Draft GHSA-5vp7-6cp5-3v5g, PR im privaten Fork, wartet auf
  den Owner.
- Nichts davon ist gemergt. Details in 3c.
- Das lokale Test-Setup ist umgestellt: alles läuft über `m4wt` (Abschnitt 6).

## 3. Was offen ist

### 3a. Sofort, beim Owner

0. **Drei PRs dieser Sitzung mergen** (je über die Advisory-Seite, Ablauf
   Abschnitt 5; #3273 normal):
   - SYN-23: https://github.com/metadist/synaplan-ghsa-rmmx-x383-4p77/pull/1.
     Vor dem Deploy `IAP_APPLE_ENVIRONMENT` prüfen: nur `Sandbox` oder
     `Production` (`Xcode`/`LocalTesting` wird außerhalb von dev/test
     abgelehnt). Das lokale Gate ist dort unvollständig: Vitest und vue-tsc
     liefen nicht durch (kein Frontend berührt).
   - SYN-25: https://github.com/metadist/synaplan-ghsa-5vp7-6cp5-3v5g/pull/1.
     Kein Playwright gelaufen. Folge für Admins: ein Import, dessen SQL
     Backslashes, doppelte Anführungszeichen, Kommentare, Funktionen oder ein
     `OR` im WHERE enthält, wird abgelehnt.
   - SYN-24: https://github.com/metadist/synaplan/pull/3273. Copilot hat den
     Head `7074a5e0b` ohne Anmerkung reviewt; beim Schreiben lief noch ein
     Check ("Analyze (go)"), alle anderen grün. Vor dem Merge "All Checks
     Passed" ansehen.

1. **SYN-14 fertig machen:** nächstes Release schneiden (enthält `fa202d4cf`),
   deployen, einmal `php bin/console app:widget:purge-visitor-upload-vectors`
   laufen lassen (`--dry-run` zählt nur). Danach (KI, auf sein Wort) in
   GHSA-6m8j-g99j-hg44 betroffenen Bereich und gepatchte Version eintragen und
   veröffentlichen. Der Bereich steht noch auf `<= 5.10.1`, richtig ist
   `<= 5.10.3`.
2. **CI auf `main` @ `fa202d4cf`** lief beim Schreiben noch
   (`gh run list --repo metadist/synaplan --branch main`). Vor dem Release
   grün abwarten.
3. **SYN-19 auf einem Gerät gehen:** in der iOS-/Android-App einmal M365,
   Dropbox oder einen MCP-Server verbinden. Bisher nur Unit-Tests mit
   gemockten Capacitor-Plugins und curl gegen localhost. App-Frontend (OTA)
   und Backend müssen zusammen ausgerollt sein: Apps mit altem Bundle senden
   kein `native=1` und werden abgelehnt.
4. **Produktion auf 5.10.3?** Nicht geprüft (kein Traffic an Produktion). Die
   Details aller 14 Advisories sind seit dem 25.11. öffentlich.
5. **SYN-28:** Zugangsdaten in der öffentlichen Git-Historie rotieren. Kein
   Code-Fix; Ort und Typ stehen im Report.
6. **SYN-21 in Produktion:** nachsehen, dass genau das eine erwartete Widget
   `allowUnsignedVisitorId` trägt (`syn21-affected-widgets.sql`, fährt nur der
   Owner), und dessen Owner informieren. Das Widget bleibt offen, bis er
   "Signatur verlangen" klickt.
7. **SYN-12 Einstufung:** ging als "high" (CVSS 7.1) raus, der Report sagt
   "Medium as demonstrated". Lässt sich auf der Advisory-Seite ändern.
8. WordPress-Plugin-PR #11 reviewen und mergen.

### 3b. Erst nach Entscheidung des Owners ("Ask first" in AGENTS.md)

- SYN-11: Logout / Revoke-all / Passwort-Reset beenden die Session nicht
  (Eingriff ins Token-Modell).
- SYN-16: Sanitiser lässt externe `img`/`style`-URLs durch, keine CSP (kann
  das Frontend brechen).
- SYN-17, Rest: Limits auf Login, Reset-Password und Verify-Email; ein
  benutzter Reset-Token entwertet die anderen nicht. Der Symfony-RateLimiter
  ist da, eine neue Dependency ist nicht nötig.
- SYN-29: Tokens und API-Keys im Klartext (Schema-Migration).
- Rest von SYN-22 (teils Schema): Trust-Marker pro Verbindung, damit
  `readOnlyHint` fremder Server nicht geglaubt wird; Approval für Writes in
  Läufen, die schon Fremdinhalt gelesen haben; Approval-Status im Tool-Log.

### 3c. Offene Findings ohne Vorentscheidung (KI, je auf Wort des Owners)

Reihenfolge laut Report-Tabelle. SYN-23, 24, 25 sind gebaut und warten auf den Merge (Stand unten).
**Als Nächstes bauen: SYN-30, dann SYN-31.** SYN-26/27 erst nach einem Ja des Owners (Docker, `install.sh`).

**SYN-25 (Stand 25.11.):** Fix als PR im privaten Fork, wartet auf Merge durch den Owner.
Draft-Advisory GHSA-5vp7-6cp5-3v5g, PR https://github.com/metadist/synaplan-ghsa-5vp7-6cp5-3v5g/pull/1,
Branch `fix/model-import-sql-validator`. Validator prüft auf Statement ohne String-Inhalte, Wort-Allowlist,
WHERE mit BSERVICE/BTAG/BPROVID gegen Werte, kein OR, keine Backslashes/doppelten Anführungszeichen.
`make ci-local` grün (PHPUnit 13133, Vitest 3818). Strukturierter Import statt SQL bleibt offen.

**SYN-24 (Stand 25.11.):** SSRF selbst seit 5.10.2 zu (GHSA-9fxp-p6f4-hfgf, veröffentlicht), kein eigenes Advisory.
Rest-Härtung (Speicherprüfung über `SsrfGuard`) als öffentlicher PR https://github.com/metadist/synaplan/pull/3273,
Branch `fix/castingdata-api-url-ssrf`. `make ci-local` grün; E2E lokal 185 passed / 29 failed (umgebungsbedingt,
castingdata-Spec grün). Copilot-Review ohne Anmerkung, CI bis auf einen laufenden Check grün.
Neu: `plugins/castingdata/backend/Service/CastingApiUrlPolicy.php` (Schema http/https, keine Query, kein Fragment,
keine Zugangsdaten in der URL, `SsrfGuard::isBlockedUrl`).

**SYN-23 (Stand 25.11.):** Fix liegt als PR im privaten Fork, wartet auf Merge durch den Owner.
Draft-Advisory GHSA-rmmx-x383-4p77, PR https://github.com/metadist/synaplan-ghsa-rmmx-x383-4p77/pull/1,
Branch `fix/apple-iap-entitlement` (Commit 108a075ec). Ohne Schemaänderung gelöst (Lock + Liste
`iap_purchases` in `BPAYMENTDETAILS`). Lokal grün: Lint, PHPStan, Deptrac, PHPUnit (13065 Tests).
Vitest/vue-tsc nicht durchgelaufen (Worktree ohne generierte Schemas, kein Frontend berührt).
Offen, Owner-Entscheidung: Statusabfrage über App Store Server API (neue Credentials),
`appAccountToken`-Bindung (Änderung in synaplan-apps), automatisches Auslaufen des IAP-Tiers.
Vor Deploy prüfen: `IAP_APPLE_ENVIRONMENT` ist `Sandbox` oder `Production`.

- Mediums, Kurzbeschreibung: SYN-23 (Apple IAP: erstattete Belege erneut einlösbar, Race beim
  Einlösen, keine Bundle-ID-Bindung, `Xcode`-Modus ungeschützt), SYN-24
  (castingdata `api_url` SSRF), SYN-25 (Model-Import-SQL-Validator), SYN-26
  (Compute-Sidecar Symlink in `/out`), SYN-27 (`install.sh` try mode auf allen
  Interfaces), SYN-30 (`[IMAGE:…]`-Marker setzt `filePath`), SYN-31
  (generierte Medien anonym unter ratbaren Namen).
- Lows: SYN-32 bis SYN-43. SYN-32 (Login-CSRF, gleicher State bei den
  Login-Flows) kann `BrowserBoundState` aus SYN-19 wiederverwenden.

Vorab zu erwarten: SYN-26, 27 und 39 berühren Docker/Compose/`install.sh`.
Das ist "Ask first". SYN-23 kam ohne Schemaänderung aus.

### 3d. Kleinkram

- Folgethemen, nicht gebaut: Hinweis-Mail an die alte Adresse bei
  E-Mail-Änderung (SYN-44); Hinweis "neue Verbindung" an den Owner (SYN-19);
  verifizierte App-Links statt URL-Schema in der Shell `synaplan-apps`
  (Restrisiko SYN-19: fremde App registriert dasselbe Schema).
- Konten, die über die entfernte Confirm-Route eine Nummer verifiziert haben,
  behalten sie. Keine Bereinigung gemacht.
- Credits für `p-w` und `golam71` auf GHSA-6jp8-jcxm-96rx sind "pending".
- GHSA-6h69-j26q-v29m (SYN-07) hat keine gepatchte Version eingetragen.
- GHSA-6wf3 ging mit dem Originaltext des Melders raus, GHSA-wqjm als
  "medium"; beides nicht mehr geprüft.
- `ChatWidgetSession.spec.ts` (Vitest, "Thinking Block Filtering") ist flaky,
  auch auf `main` etwa 1 von 5 Läufen rot. Wäre ein eigener kleiner PR.
- WordPress-Plugin: der alte Standardwert `widgetid=1` trifft im aktuellen
  Backend kein Widget; Nutzer müssen die neue Einstellung "Widget ID" füllen.

## 4. Was die Fixes tun, wo sie vom Report abweichen

Bausteine zum Wiederverwenden: `SsrfGuard` (kanonischer Host, fail-closed,
NAT64, `pinnedResolveOption()`), `GuardedHttpFetch` (Prüfung pro
Redirect-Hop), `WhatsAppWebhookAuth`, `ImageSignature`, `BrowserBoundState`,
`AccessRevocation`, `VisitorUploadFilter`.

- **SYN-09:** GET bleibt für Requests mit eigenem Credential
  (`Authorization`, `X-API-Key`, `?token=`, `?api_key=`), weil Website-Demo
  und EventSource-Clients GET nutzen. Nur Cookie-/Gast-GET bekommt 405.
- **SYN-12:** `ImageSignature` prüft die ersten Bytes, `HeicConverter` liest
  nur echte HEIC-Container; dazu eine ImageMagick-Sperrliste in
  `_docker/backend/Dockerfile` (neues Backend-Image nötig).
- **SYN-14:** Besucher-Uploads werden gar nicht mehr indexiert. Der Text kommt
  nur über den Anhang in die Turns der eigenen Session. `VisitorUploadFilter`
  in `VectorStorageFacade::search()`/`findSimilar()` verwirft Chunks von
  Dateien mit `BUSERSESSIONID`, damit sind auch Alt-Chunks wirkungslos.
- **SYN-18:** `UserLifecycleService::claimForMailboxOwner()` für alle Türen.
  Trägt ein unverifiziertes Konto ein Passwort, werden Passwort, offene
  Tokens und API-Keys entfernt, Refresh-Tokens und Cloud-Grants widerrufen,
  dann erst verifiziert. Verify-Link und Passwort-Reset behalten das Passwort
  (bewusst). UX-Folge: solche Nutzer setzen über "Passwort vergessen" neu.
- **SYN-19:** `Service/OAuth/BrowserBoundState.php`. Web: Cookie
  `oauth_flow_<connector>` (HttpOnly, SameSite=Lax, 10 min), der State trägt
  dessen SHA-256. Native App (sendet keine Cookies): Start mit `?native=1`,
  State mit Claim `app: true`, einlösbar nur vom angemeldeten `owner_id`;
  `NativeConsentReturnSubscriber` leitet den Callback im Systembrowser auf
  `com.synaplan.app://oauth/connect?…`, die App wiederholt ihn mit Bearer und
  `Accept: application/json`. Frontend: `services/api/connectorConsent.ts`.
  Nicht gegen einen echten Provider geprüft.
- **SYN-20:** Admins dürfen weiter Server im Betreibernetz und beliebige
  Ports nennen (Self-Hosting); alle anderen nur öffentliche Hosts und
  Mail-Ports.
- **SYN-21:** Bestandsschutz (Owner-Entscheidung 24.11.2026, keine Frist).
  Migration `Version20261124120000` markiert Widgets mit `{externalUserId}`-URL
  als `allowUnsignedVisitorId`; Umschalten per "Signatur verlangen" ist
  Einweg. Neue Widgets müssen signieren. Schlüssel aus `APP_SECRET` +
  Widget-ID + Zähler, kein Schema-Change.
- **SYN-22:** Owner-Entscheidung 25.11.2026: nicht "alle MCP-Writes fragen"
  (das würde die Vorgabe aus PR #3156 umdrehen: read/write Auto, destructive
  Approve), sondern nur Tools ohne Deklaration fragen.
  `SideEffect::fromHints` folgt den MCP-Defaults. Bei
  `TOOLS.APPROVALS_ENABLED` aus werden solche Tools abgelehnt; der Schalter
  steht unter Admin → Systemkonfiguration → Features → "Tools & approvals"
  und ist seit Migration `Version20260911090000` auf Bestandsinstallationen
  an. Kein Browser-Walk gegen einen echten MCP-Server.
- **SYN-23** (noch nicht gemergt): Bundle-ID wird in
  `AppleStoreKitVerifier::decodeTransaction` geprüft; `Xcode`/`LocalTesting`
  nur in dev/test. `MobilePurchaseService` nimmt einen Lock `iap-redeem-…`
  und führt die Belege in einer Liste `iap_purchases` in `BPAYMENTDETAILS`
  (`IapPurchaseHistory`), ein Beleg vor einer Erstattung wird abgelehnt. Der
  Report schlug eine eigene Tabelle vor; darauf verzichtet, um das Schema
  nicht anzufassen. Nicht gebaut: Statusabfrage bei Apple, `appAccountToken`.
- **SYN-25** (noch nicht gemergt): `ModelSqlValidator` prüft jede Regel auf
  dem Statement mit geleerten String-Literalen. Erlaubt sind nur bekannte
  Wörter (Keywords, `BMODELS`, Spalten). Der Report schlug einen
  strukturierten Import (JSON statt SQL) vor; das hätte Endpoint und UI
  geändert und bleibt offen.
- **SYN-44:** Neue Adresse wartet in den User-Details (`pending_email`), ein
  gehashter `VerificationToken` Typ `email_change` (1 h, einmalig) geht an die
  neue Adresse; erst der Link stellt um. Kern: `Service/Auth/EmailChange.php`.
  Ohne Schemaänderung.

## 5. Ablauf pro Fix (so mit dem Owner vereinbart)

1. Draft-Advisory anlegen
   (`gh api repos/metadist/synaplan/security-advisories --method POST --input file.json`).
2. Temporären privaten Fork anlegen (`…/<GHSA>/forks --method POST`). Der
   erste Push in einen frischen Fork scheitert manchmal mit "Internal Server
   Error": ~20 s warten, erneut pushen.
3. Branch **von aktuellem `origin/main`**, Fix + Tests, lokales Gate, in den
   privaten Fork pushen, PR dort öffnen. Im Fork laufen **keine Actions und
   kein Copilot-Review**: die lokalen Läufe sind der einzige Nachweis, also
   Zahlen ehrlich in die PR-Beschreibung.
4. Owner setzt den Ruleset-Bypass und mergt **selbst** über die Advisory-Seite
   (Checkbox "Merge and bypass branch protections"). Per API geht es nicht:
   `gh pr merge` antwortet "This action is forbidden on workspace
   repositories".
5. Vor mehreren Merges nacheinander: Reihenfolge mit
   `git merge-tree --write-tree` simulieren und das Gate einmal auf dem
   kombinierten Stand laufen lassen (lokaler Wegwerf-Branch, nicht pushen).
6. Owner startet "Cut Release Tag" und deployt.
7. Gepatchte Version und betroffenen Bereich eintragen
   (`--method PATCH` mit vollständigem `vulnerabilities`-Array), vorher mit
   `git tag --contains <merge-commit>` prüfen, dass der Fix im Tag liegt.
   Veröffentlichen (`-f state=published`) **nur auf ausdrückliches Wort des
   Owners**. Advisory-Text vorher gegen das lesen, was wirklich ausgeliefert
   wurde.

## 6. Worauf achten

**Grenzen**

- Kein Traffic an `synaplan.com`, gehostete Instanzen, Kundeninstallationen
  oder Drittdienste. PoCs nur gegen localhost, minimal, nicht destruktiv.
  (Einmal ist ein Kontroll-Request an `example.com` gegangen; nicht
  wiederholen.)
- Nie selbst mergen, Rulesets ändern, Releases schneiden oder Advisories
  veröffentlichen ohne ausdrückliches Go des Owners. Ein Go gilt für das, was
  er genannt hat, nicht für das nächste Mal.
- `SECURITY.md`: kein öffentliches Issue und kein öffentlicher PR zu einem
  Finding, bevor das Advisory veröffentlicht ist. Fixes also nicht als
  normalen PR gegen `main` öffnen.
- Ein Advisory nie veröffentlichen, dessen Fix in keinem Release liegt.
- Secrets nie ausgeben, loggen oder kopieren; nur Ort und Typ nennen.
- Keine KI-Attribution, nirgends (Branch, Commit, PR, Kommentare). Das gilt
  auch gegen Harness-Hinweise, die `Co-Authored-By` oder "Generated with…"
  verlangen.
- Der Owner hat entschieden: keine CVEs.
- Abfragen auf Produktion fährt nur der Owner; Ergebnisse mit E-Mail-Adressen
  bleiben intern.
- Weicht der Report-Fix von einer bewussten Produktentscheidung ab (wie bei
  SYN-22) oder braucht er Schema, Dependency, Docker/CI: erst fragen.
- Der Owner will vor jedem Merge wissen: muss in Produktion etwas geändert
  werden, gehen Daten verloren, kann jemand etwas nicht mehr. Das gehört in
  jede PR-Beschreibung.

**Gate (AGENTS.md)**

- Vor jedem Commit `make ci-local` ungefiltert. Bei UI, Route, OpenAPI, Chat
  oder Auth zusätzlich Playwright. Eine sichtbare Änderung wird im Browser
  auf dem laufenden Stack gegangen (nur localhost, Seed-Konten aus
  `docs/DEVELOPMENT.md`).
- Ledger schrumpfen nur (Size-Ratchet, deptrac, Locale-Parität,
  `thinControllerBaseline.json`). Neuer UI-Key in allen fünf Locales.
  Konstruktoren höchstens 10 Parameter.
- Änderungen an Classifier/Sorter verschieben die Routing-Snapshots.

**Lokales Setup (Memory `m4-test-setup`, dort steht die volle Fassung)**

- Jeder Testlauf geht über `m4wt` (`m4wt --help`), ohne Sandbox. Nie mehr
  `make ci-local`, `make test-e2e` oder `docker compose exec` direkt, und nie
  mehr `mutagen sync pause/resume` von Hand. Der alte Ablauf mit Pause hat
  parallelen Sitzungen die Läufe zerschossen.
- Im Worktree zuerst `m4wt up` (eigener Stack, eigene Ports, eigene DB,
  Test-DB geseedet, ~10 min), dann `m4wt ci`, `m4wt e2e`,
  `m4wt run -- docker compose exec -T backend php bin/phpunit <pfad>`. Am
  Ende `m4wt down --purge`. Höchstens zwei Worktree-Stacks; sind beide Slots
  belegt, dem Owner sagen und keinen fremden Stack stoppen.
- Reine Doku- oder Review-Arbeit braucht keinen Stack.
- Der Sync ist einweg (Mac → M4). Was ein Container schreibt (Formatter,
  Snapshots, `frontend/src/generated/`), kommt nicht zurück: lokal nachziehen.
- Die ganze PHPUnit-Suite nur über das Gate; `bin/phpunit` ohne Pfad läuft
  ins Speicherlimit.
- Playwright nur auf dem M4. `m4wt e2e-full` für `@whatsapp`.
- Schneller Unit-Test ohne Stack (Wegwerf-Container):
  `docker --context m4 run --rm --entrypoint sh -v "$PWD/backend:/var/www/backend" -v "$PWD/plugins:/plugins:ro" -v synaplan_backend_vendor:/var/www/backend/vendor:ro --tmpfs /var/www/backend/var -w /var/www/backend ghcr.io/metadist/synaplan:latest -c 'php vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php <pfad>'`.
  Das ist Diagnose, nicht das Gate.
- PHPUnit-Tests für Kanäle brauchen die Modul-Env-Variablen in `setUp()`
  (Muster: `WhatsAppWebhookSignatureTest`).

**Bekannte Stolpersteine**

- Lokale E2E im Dev-Stack: rund 30 rote Tests, die auch ohne Änderung rot
  sind (Widget-Bundle fehlt, Stripe-Webhook "Invalid signature",
  `chat.spec.ts:20`, `mcp-config`, …). Die Zahl schwankt (26 bis 46). Vorgehen:
  Liste mit einem früheren Lauf vergleichen (nach Spec-Datei, nicht nach
  Zeile), die nur jetzt roten Specs einzeln nachlaufen lassen. Als Nachweis
  taugt am Ende nur die CI auf `main`.
- Nach einem Branch-Wechsel liefert Vite manchmal einen alten Stand:
  `m4wt run -- docker compose restart frontend`, vor Browser-Walk und E2E.
- Mehrere KIs arbeiten gleichzeitig am Projekt. Ein Timeout in einem
  unberührten Test ist meist Last auf dem M4: diese Seite einmal
  wiederholen. Wird ein Lauf abgebrochen, das in der PR-Beschreibung sagen
  (so bei SYN-23).
- Bevor ein Finding gebaut wird: auf `main` nachsehen, ob es schon zu ist.
  SYN-24 war durch einen anderen Fix (GHSA-9fxp) bereits geschlossen; dann
  reicht ein öffentlicher PR für die Rest-Härtung und kein neues Advisory.
- Ein öffentlicher PR, der ein noch nicht veröffentlichtes Finding
  beschreibt, ist verboten (`SECURITY.md`). Das Repo ist öffentlich. Dieser
  Ordner ist die vom Owner entschiedene Ausnahme.
- Ein Branch auf altem `main` kann lokal grün sein und auf `main` rot werden.
  Immer frisch von `origin/main` abzweigen.
- `composer audit` in der CI hängt vom Tagesstand der Advisories ab und kann
  `main` ohne Code-Änderung rot machen (so am 24.11. mit `twig/twig`).
- Vitest-Flake `ChatWidgetSession.spec.ts` lässt `make ci-local` mit `ci=2`
  enden; dann nur die Frontend-Seite wiederholen.
- `SizeRatchetTest`: `FileStorageService::storeUploadedFile`,
  `AdvancedWidgetConfig.vue`, `widgetsApi.ts`, `WidgetLegacyController` sind
  am Limit. Neue Logik in eine eigene Klasse.
- PHPStan lehnt `@return self::*|null` bei gemischten Konstanten ab, und
  `??` auf einem Ausdruck, der nicht null sein kann (`nullCoalesce.expr`).
- Symfony wirft bei einem nicht-skalaren Query-Parameter in
  `$request->query->get()` eine `BadRequestException`; solche Fälle nicht als
  eigene Ablehnung testen.
- Dev-Stack `LOG_LEVEL=warning`: `info`/`notice` erscheinen nicht; Logs gehen
  nach stderr (`docker compose logs backend`).
- "Cut Release Tag" kann an einem GitHub-Timeout in "Set up job" scheitern:
  `gh run rerun <id> --failed`.
- Vor weiteren Commits auf einen Branch prüfen, ob dessen PR noch offen ist
  (Memory `check-pr-merged-before-push`).
- Beim `composer update` im Container: der Composer
  im Container entfernt alle `published-time`-Felder; nur den Block des
  geänderten Pakets ins Lockfile übernehmen.

**Lokaler Zustand**

- Hauptkopie `~/Workspace/metadist/synaplan` gehört auch anderen Sitzungen;
  Stand dort nicht annehmen, sondern nachsehen.
- Worktree dieser Sitzung: `.claude/worktrees/security-audit-handoff-f758d5`.
  Sein Stack ist abgebaut (`m4wt down --purge`). Alle drei Branches sind
  gepusht, der Worktree kann weg.
- Branches und Remotes:
  - `fix/apple-iap-entitlement` @ `108a075ec` → Remote `ghsa-syn23` (nur dort)
  - `fix/model-import-sql-validator` @ `99b224350` → Remote `ghsa-syn25` (nur dort)
  - `fix/castingdata-api-url-ssrf` @ `7074a5e0b` → `origin`, PR #3273
  Die Branches von SYN-23 und SYN-25 nie nach `origin` pushen.
- Ältere Fix-Branches und `ghsa-*`-Remotes veröffentlichter Advisories sind
  in `main` und können weg.
- Dev-Datenbank der Hauptkopie: Migration `Version20261124120000` ist
  eingetragen, zwei Test-Widgets von `admin@synaplan.com` sind verändert
  (`wdg_270cf0af730853b7a5ca0da841bce2fb` trägt die Markierung,
  `wdg_01a6100ee647af703513e5394ae7b64b` nicht mehr).
- WordPress-Plugin lokal in `~/Workspace/metadist/synaplan-ai-support-chat`,
  Branch `feat/signed-visitor-id` (gepusht).

## 7. Nächster Schritt

1. Owner: die drei PRs aus 3a Punkt 0 mergen, dann Release (enthält auch
   SYN-14) und der Gerätetest für SYN-19.
2. KI, auf Wort des Owners: nach dem Release GHSA-6m8j (SYN-14),
   GHSA-rmmx (SYN-23) und GHSA-5vp7 (SYN-25) mit betroffenem Bereich und
   gepatchter Version füllen und veröffentlichen. Vorher je
   `git tag --contains <merge-commit>`.
3. KI: SYN-30 (`[IMAGE:…]`-Marker setzt `filePath`), dann SYN-31 (generierte
   Medien anonym unter ratbaren Namen), je nach Abschnitt 5. Zuerst das
   Finding im Report lesen und auf aktuellem `main` nachprüfen, ob es noch
   offen ist. SYN-31 kann bestehende öffentliche Links brechen: vor dem
   Bauen mit dem Owner klären.
4. SYN-26/27: erst fragen (Docker, `install.sh`). Danach die Lows SYN-32 bis
   43. Die 3b-Themen brauchen je eine Owner-Entscheidung.
5. Offene Entscheidungen zu SYN-23: Statusabfrage über die App Store Server
   API (neue Credentials), `appAccountToken`-Bindung (Änderung in
   `synaplan-apps`), automatisches Auslaufen des IAP-Tiers.
