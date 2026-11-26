# Handoff: Synaplan Security-Audit und Fixes

Stand: 2026-11-26, mittags. `main` @ `4cb153030`, letzter Tag `v5.10.3`.
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

31 Advisories im Repo: 30 veröffentlicht, kein Draft, 1 als Duplikat
geschlossen (GHSA-2xmp-mxfh-h3f2). Von den 30 gehören 26 zu diesem Audit
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
| SYN-07 Synaform URL-Quellen SSRF | GHSA-6h69-j26q-v29m | 5.9.0 (PR #3226) | published |
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
| SYN-14 Besucher-Uploads im geteilten RAG-Index | GHSA-6m8j-g99j-hg44 | 5.10.4 | published 26.11. |
| SYN-23 Apple-In-App-Käufe | GHSA-rmmx-x383-4p77 | 5.10.4 | published 26.11. |
| SYN-25 Admin-Modell-Import, SQL-Prüfung | GHSA-5vp7-6cp5-3v5g | 5.10.4 | published 26.11. |
| SYN-30 Medien-Marker setzt Dateipfad | GHSA-grvv-mwcf-vhpm | 5.10.4 | published 26.11. |
| SYN-31 Generierte Medien unter ratbaren Namen | GHSA-r233-vw39-4hm9 | 5.10.4 | published 26.11. |

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

- SYN-23 gebaut: Draft GHSA-rmmx-x383-4p77, PR im privaten Fork (am 26.11. gemergt).
- SYN-24 geprüft: die SSRF ist seit 5.10.2 zu. Nur die Prüfung beim Speichern
  fehlte, die liegt als öffentlicher PR #3273 vor (kein Advisory nötig, weil
  nichts Unveröffentlichtes verraten wird).
- SYN-25 gebaut: Draft GHSA-5vp7-6cp5-3v5g, PR im privaten Fork (am 26.11. gemergt).
- Details in 3c.
- Das lokale Test-Setup ist umgestellt: alles läuft über `m4wt` (Abschnitt 6).

**Was am 26.11.2026 dazukam (Sitzung 3)**

- PR #3273 (SYN-24, Rest-Härtung) ist gemergt (`e2d267d4d`).
- SYN-30 auf `main` nachgeprüft: war offen und braucht keine Prompt-Injection,
  der Aufrufer schreibt den Prompt selbst. Gebaut: Draft GHSA-grvv-mwcf-vhpm,
  PR im privaten Fork (am 26.11. gemergt). Details in 3c.
- SYN-07: der Fix (`e2d5123ae`, PR #3226) liegt laut `git tag --contains` seit
  `v5.9.0` in einem Release. Das Feld im Advisory ist noch nicht gefüllt.
- Die CI auf `main` war am 26.11. rot (`composer audit` nach dem
  Sicherheits-Release von Symfony). PR #3276 (Symfony 8.1.12) ist gemergt.
- SYN-31 mit dem Owner geklärt: alte Links laufen weiter, ohne Enddatum.
  Gebaut: Draft GHSA-r233-vw39-4hm9. Details in 3c.
- Auf Wort des Owners gemergt (je über die Advisory-Seite): SYN-23
  (`57fa0b5cf`), SYN-25 (`0be250b77`), SYN-30 (`1a85eaeb4`), SYN-31
  (`aa1a57502`). Vorher lief das Gate auf dem zusammengeführten Stand:
  `make ci-local` grün (PHPUnit 13245, Vitest 3818), Playwright `@ci` 225
  passed / 2 skipped / 1 failed (`widget.spec.ts:159`, ein Timing-Rennen im
  Test: die Antwort steht im Seitenabbild, der Text wird zu früh gelesen).
- `v5.10.4` ist am 26.11. erschienen und enthält laut `git tag --contains`
  alle fünf Fixes (SYN-14, 23, 25, 30, 31). Auf Wort des Owners: die fünf
  Advisories mit `<= 5.10.3` / `5.10.4` gefüllt und veröffentlicht; in
  GHSA-6h69 (SYN-07) `5.9.0` eingetragen. Produktion läuft laut Owner auf
  5.10.4 (von der KI nicht geprüft).
- WordPress-Plugin-PR #11 ist gemergt.

## 3. Was offen ist

### 3a. Sofort, beim Owner

0. **Beim Deploy von 5.10.4** (falls noch nicht geschehen):
   - SYN-23: `IAP_APPLE_ENVIRONMENT` prüfen: nur `Sandbox` oder `Production`
     (`Xcode`/`LocalTesting` wird außerhalb von dev/test abgelehnt).
   - SYN-25: Folge für Admins: ein Import, dessen SQL Backslashes, doppelte
     Anführungszeichen, Kommentare, Funktionen oder ein `OR` im WHERE
     enthält, wird abgelehnt.
   - SYN-30: die Lese-Abfrage für Altzeilen steht in
     https://github.com/metadist/synaplan-ghsa-grvv-mwcf-vhpm/pull/1 (fährt
     nur der Owner).
   - SYN-31: die Migration muss mit dem Deploy laufen (eine Zeile in
     `BCONFIG`, kein Schema). Ohne sie antworten alte Links für nicht
     angemeldete Besucher mit 404.
1. Erledigt am 26.11. (Owner): SYN-14 in Produktion bereinigt mit
   `app:widget:purge-visitor-upload-vectors`. Der Probelauf zählte 195
   Chunks aus 213 Besucher-Uploads; 3 Uploads ohne Widget oder Sitzung
   blieben liegen (die Suche liefert sie nicht aus).
2. Erledigt: CI auf `main` ist seit PR #3276 (Symfony 8.1.12) wieder grün.
3. **SYN-19 auf einem Gerät gehen:** in der iOS-/Android-App einmal M365,
   Dropbox oder einen MCP-Server verbinden. Bisher nur Unit-Tests mit
   gemockten Capacitor-Plugins und curl gegen localhost. App-Frontend (OTA)
   und Backend müssen zusammen ausgerollt sein: Apps mit altem Bundle senden
   kein `native=1` und werden abgelehnt.
4. Erledigt: Produktion läuft laut Owner seit dem 26.11. auf 5.10.4.
5. Erledigt am 26.11. (Owner): die Zugangsdaten aus SYN-28 sind laut Owner
   rotiert (Turnstile-Secret des Widgets `synaplan.com`, SES-SMTP-Zugang).
   Nicht geprüft: der Google-API-Key in der gespeicherten Doku-Seite, und ob
   die alten Zugänge fremd benutzt wurden. Offen: Secret Scanning mit Push
   Protection in GitHub einschalten.
6. **SYN-21 in Produktion:** nachsehen, dass genau das eine erwartete Widget
   `allowUnsignedVisitorId` trägt (`syn21-affected-widgets.sql`, fährt nur der
   Owner), und dessen Owner informieren. Das Widget bleibt offen, bis er
   "Signatur verlangen" klickt.
7. **SYN-12 Einstufung:** ging als "high" (CVSS 7.1) raus, der Report sagt
   "Medium as demonstrated". Lässt sich auf der Advisory-Seite ändern.
8. Erledigt: WordPress-Plugin-PR #11 ist gemergt.

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

Reihenfolge laut Report-Tabelle. SYN-23, 24, 25, 30 und 31 sind in 5.10.4 erschienen (Stand unten).
**Als Nächstes: SYN-26/27, aber erst nach einem Ja des Owners** (Docker, `install.sh`).

**SYN-30 (Stand 26.11.):** gemergt (`1a85eaeb4`), in 5.10.4, veröffentlicht.
Advisory GHSA-grvv-mwcf-vhpm, PR https://github.com/metadist/synaplan-ghsa-grvv-mwcf-vhpm/pull/1,
Branch `fix/media-marker-file-path` (Commit `ec56d9ccf`). `make ci-local` grün (PHPUnit 13143, Vitest 3818),
Playwright `@ci` 226 passed / 2 skipped. Nicht gegen ein echtes Modell geprüft.
Offen, Owner-Entscheidung: die Datei-Routen nehmen weiter die erste Message mit passendem Pfad als Besitzer.
Eine Prüfung dort würde auch Altzeilen abdecken, kann aber ändern, wer bestehende Links öffnen darf.

**SYN-31 (Stand 26.11.):** gemergt (`aa1a57502`), in 5.10.4, veröffentlicht.
Advisory GHSA-r233-vw39-4hm9, PR https://github.com/metadist/synaplan-ghsa-r233-vw39-4hm9/pull/1,
Branch `fix/generated-media-names`. Backend-Gate grün (PHPUnit 13174); Vitest fiel im Gesamtlauf in
`ChatWidgetSession.spec.ts` (nicht berührt) und war im Einzellauf grün (3818). Playwright `@ci` 225 passed /
2 skipped / 1 failed (`widget.spec.ts:159`, leere Antwort; die Datei allein danach 10 passed, nicht weiter untersucht).
Kein Browser-Walk, kein WhatsApp-Versand gegen Meta.
Vorgabe des Owners: alte Links laufen weiter, ohne Enddatum. Dateien von vor dem Upgrade bleiben deshalb so
ratbar wie bisher. Schließen lässt sich das später über `MEDIA / LEGACY_PUBLIC_NAMES_UNTIL` = `0`
(alle alten Links enden für nicht angemeldete Besucher) oder durch Umbenennen der Altdateien.

**SYN-25 (Stand 26.11.):** gemergt (`0be250b77`), in 5.10.4, veröffentlicht.
Advisory GHSA-5vp7-6cp5-3v5g, PR https://github.com/metadist/synaplan-ghsa-5vp7-6cp5-3v5g/pull/1,
Branch `fix/model-import-sql-validator`. Validator prüft auf Statement ohne String-Inhalte, Wort-Allowlist,
WHERE mit BSERVICE/BTAG/BPROVID gegen Werte, kein OR, keine Backslashes/doppelten Anführungszeichen.
`make ci-local` grün (PHPUnit 13133, Vitest 3818). Strukturierter Import statt SQL bleibt offen.

**SYN-24 (erledigt 26.11.):** SSRF selbst seit 5.10.2 zu (GHSA-9fxp-p6f4-hfgf, veröffentlicht), kein eigenes Advisory.
Rest-Härtung (Speicherprüfung über `SsrfGuard`) gemergt mit PR #3273 (`e2d267d4d`), in 5.10.4.
Neu: `plugins/castingdata/backend/Service/CastingApiUrlPolicy.php` (Schema http/https, keine Query, kein Fragment,
keine Zugangsdaten in der URL, `SsrfGuard::isBlockedUrl`).

**SYN-23 (Stand 26.11.):** gemergt (`57fa0b5cf`), in 5.10.4, veröffentlicht.
Advisory GHSA-rmmx-x383-4p77, PR https://github.com/metadist/synaplan-ghsa-rmmx-x383-4p77/pull/1,
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
- **SYN-23:** Bundle-ID wird in
  `AppleStoreKitVerifier::decodeTransaction` geprüft; `Xcode`/`LocalTesting`
  nur in dev/test. `MobilePurchaseService` nimmt einen Lock `iap-redeem-…`
  und führt die Belege in einer Liste `iap_purchases` in `BPAYMENTDETAILS`
  (`IapPurchaseHistory`), ein Beleg vor einer Erstattung wird abgelehnt. Der
  Report schlug eine eigene Tabelle vor; darauf verzichtet, um das Schema
  nicht anzufassen. Nicht gebaut: Statusabfrage bei Apple, `appAccountToken`.
- **SYN-25:** `ModelSqlValidator` prüft jede Regel auf
  dem Statement mit geleerten String-Literalen. Erlaubt sind nur bekannte
  Wörter (Keywords, `BMODELS`, Spalten). Der Report schlug einen
  strukturierten Import (JSON statt SQL) vor; das hätte Endpoint und UI
  geändert und bleibt offen.
- **SYN-30:** `MediaMarkerReader` liest den Marker für
  beide Routen. Angenommen wird nur eine vorhandene Datei im eigenen
  Upload-Verzeichnis des Kontos; eine https-Adresse nur in Antworten des
  eingebauten Demo-Providers (`test`). Der Report nannte den Befund
  "Suspected" und verlangte ein echtes Modell zum Nachweis; das ist nicht
  nötig, weil der Aufrufer den Prompt selbst schreibt.
- **SYN-31:** `PublicMediaName` baut alle anonym
  ausgelieferten Namen mit 128 Zufallsbits. `AnonymousMediaGate` entscheidet
  allein, was die Datei-Route ohne Anmeldung ausliefert: einen Zufallsnamen
  immer, einen Namen alter Form nur, wenn die Zeit im Namen vor dem Upgrade
  der Instanz liegt (`BCONFIG`-Zeile aus einer Migration, Upgrade plus ein
  Tag für Rolling Deploys). Ohne lesbare Zeile wird kein alter Name
  ausgeliefert. Der Report schlug signierte URLs oder ein eigenes Verzeichnis
  vor; beides hätte die alten Links gebrochen.
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

**Lokales Setup**

- Das persönliche Test-Setup dieses Rechners steht im Memory `m4-test-setup`
  und gehört nicht ins Repo. Dort nachlesen; es ändert sich öfter als diese
  Datei. Kurz: jeder Testlauf geht über `m4wt` (`m4wt ci`, `m4wt e2e`,
  `m4wt make <target>`), der Stack startet und stoppt pro Lauf von selbst.
  Ein `m4wt up` vorab ist nur für einen Browser-Walk nötig.
- Reine Doku- oder Review-Arbeit braucht keinen Stack.
- Der Sync ist einweg (Mac → M4). Was ein Container schreibt (Formatter,
  Snapshots, `frontend/src/generated/`), kommt nicht zurück: lokal nachziehen.
- Die ganze PHPUnit-Suite nur über das Gate; `bin/phpunit` ohne Pfad läuft
  ins Speicherlimit.
- PHPUnit-Tests für Kanäle brauchen die Modul-Env-Variablen in `setUp()`
  (Muster: `WhatsAppWebhookSignatureTest`).

**Bekannte Stolpersteine**

- `m4wt e2e` (gebautes Image, Test-Provider, wie in der CI) lief am 26.11.
  ohne rote Tests durch. Die folgende Liste gilt nur für `m4wt e2e --dev`.
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
- `MessageController` hat 14 Konstruktor-Parameter und ist damit am
  eingefrorenen Limit. Ein weiterer Dienst kommt als Action-Argument in die
  Methode (so bei SYN-30), nicht in den Konstruktor.
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
- Worktree der Sitzung vom 26.11.:
  `.claude/worktrees/security-audit-handoff-754513`. Alle Branches sind
  gepusht.
- Branches und Remotes:
  - `fix/apple-iap-entitlement` @ `108a075ec` → Remote `ghsa-syn23` (nur dort)
  - `fix/model-import-sql-validator` @ `99b224350` → Remote `ghsa-syn25` (nur dort)
  - `fix/media-marker-file-path` @ `ec56d9ccf` → Remote `ghsa-syn30` (nur dort)
  Diese drei Branches nie nach `origin` pushen.
- Ältere Fix-Branches und `ghsa-*`-Remotes veröffentlichter Advisories sind
  in `main` und können weg.
- Dev-Datenbank der Hauptkopie: Migration `Version20261124120000` ist
  eingetragen, zwei Test-Widgets von `admin@synaplan.com` sind verändert
  (`wdg_270cf0af730853b7a5ca0da841bce2fb` trägt die Markierung,
  `wdg_01a6100ee647af703513e5394ae7b64b` nicht mehr).
- WordPress-Plugin lokal in `~/Workspace/metadist/synaplan-ai-support-chat`,
  Branch `feat/signed-visitor-id` (gepusht).

## 7. Nächster Schritt

1. Owner: nach dem Deploy von 5.10.4 die Punkte aus 3a Punkt 0 nachsehen
   (`BCONFIG`-Zeile zu SYN-31, `IAP_APPLE_ENVIRONMENT`), dann der Gerätetest
   für SYN-19.
2. Erledigt am 26.11.: alle Advisories dieses Audits sind veröffentlicht.
3. SYN-26/27: erst fragen (Docker, `install.sh`). Danach die Lows SYN-32 bis
   43. Die 3b-Themen brauchen je eine Owner-Entscheidung.
4. Offene Entscheidungen: zu SYN-23 Statusabfrage über die App Store Server
   API (neue Credentials), `appAccountToken`-Bindung (Änderung in
   `synaplan-apps`), automatisches Auslaufen des IAP-Tiers; zu SYN-30 die
   Besitzerprüfung in den Datei-Routen; zu SYN-31, ob und wann die alten
   Namen enden.
