# Functional tests after each sprint

A sprint is finished when its three test layers are green **and** its walks
are dated entries in [`STATUS.md`](./STATUS.md). This file says, per sprint,
exactly what runs, what is clicked, and what must be seen. The briefs in
[`08_agent_briefs.md`](./08_agent_briefs.md) name the unit tests; this file
owns everything that needs a running stack.

---

## 0. Rules for every sprint

### 0.1 Three layers, all three

| Layer | What | Who runs it | Where it lives |
| ----- | ---- | ----------- | -------------- |
| **L1 Unit and contract** | The repo gates (`06` §0) plus the tests each brief names | the agent, before every commit | each repo's test suite |
| **L2 Integration scripts** | Bash + curl against the local stacks, one script per feature, `PASS` / `FAIL` per assertion, non-zero exit on the first failure | the agent before the PR; everyone after each sprint | `synaplan/_devextras/testing/cloud-apps/` (E-6) |
| **L3 Browser walks** | The journeys of `05` §1 clicked end to end on each cloud; Playwright where a stack supports it (Nextcloud from CF1.3, OpenCloud today), by hand where it does not (ownCloud.online) | a person for the sprint exit; agents may walk during development | checklists below; results in STATUS |

A filtered L1 run, a single L2 script or one L3 journey is diagnostic. The
sprint exit needs the unfiltered gates, the full L2 set of that sprint and
every L3 checklist row.

### 0.2 Environment and accounts

`env-check.sh --cloud all` (`10` §5) is green before an L2 or L3 run, with
`--oidc` from CF2 and `--compute` for J-CF-13. The STATUS entry copies its
`versions` block.

| Role | Synaplan | Nextcloud | ownCloud.online | OpenCloud (Keycloak) |
| ---- | -------- | --------- | --------------- | -------------------- |
| **Ada** — owner of files and watches | `demo@synaplan.com` | `ada` | `ada` | `ada` |
| **Bob** — colleague with a share, no Synaplan link | — (J-CF-10 is the point: Bob has none) | `bob` | `bob` | `bob` |
| **Cloud admin** | — | `admin` | `admin` | `admin` |
| **Synaplan admin** | seeded admin | — | — | — |

Mail goes to MailHog (`http://localhost:8025`). Every script and checklist
uses these names; a walk with other accounts is recorded as such.

### 0.3 Fixtures (`_devextras/testing/cloud-apps/fixtures/`, E-6)

| File | Content | Used by |
| ---- | ------- | ------- |
| `spec.pdf` | 2 pages of text, title "Widget spec" | J-CF-5, status checks |
| `contract.docx` | 1 page German text, heading "Vertrag" | J-CF-7 translate |
| `contract-scan.pdf` | 1 page image only, no text layer | J-CF-7 "no readable text" |
| `price-list.docx` | 1 page German price list, two versions (`price-list-v2.docx` changes three prices) | J-CF-15 file watch |
| `report.pdf` | 3 pages of text, 10 numbered findings | J-CF-8 summarize |
| `invoice-0912.pdf`, `invoice-1003.pdf` | one-page invoices with vendor, date, total, IBAN | J-CF-9, J-CF-14 |
| `export-01.csv`, `export-02.csv`, `export-03.csv` | `month,revenue` with 12 rows each | J-CF-13 file work |
| `chart.png` | 200 × 120 px image | J-CF-3 copy |
| `notes.md`, `plan.pptx` | small documents | From Synaplan picker, kinds |
| `big-101mb.bin` | generated at run time (`head -c 101M /dev/urandom`), never committed | size limits |
| `many/` | 5 000 empty `.txt` files generated at run time | status call performance |

`make-fixtures.sh` generates the run-time files; the committed set stays under
200 KB. The cloud helpers (`nextcloud.sh`, `owncloud.sh`, `opencloud.sh`)
offer `upload <user> <path> <file>`, `wait-for <user> <path>`,
`share <owner> <path> <with> <perm>`, `trash <user> <path>`,
`restore <user> <path>`, `occ <args>` (Nextcloud / ownCloud) — the same verbs
on all three, so a script reads the same whichever cloud it targets
(`CLOUD=nextcloud ./cf5-watch.sh`).

### 0.4 Script conventions

- Source `lib.sh` from `_devextras/testing/platform-links/` (`login`,
  `http_json`, `assert_eq`, `summary`, `enable_flag`, `disable_flag`) through
  the new `cloud-apps/lib.sh`, which adds `wait_until <seconds> <cmd>`
  (polls every 2 s — no fixed sleeps), `mailhog_count <subject>`,
  `db_query <sql>` (dev-only evidence from `BCLOUD*` tables) and the cloud
  helpers.
- Each script creates what it needs under a unique prefix
  (`cf3-<epoch>-…`), cleans up at the end, and is safe to run twice.
- Timeouts come from the plan: 30 s copy budget, 60 s quiet period, 10 min
  pickup wait. A script waits at most the plan's number plus 30 s.
- A script never reads a secret from the repo; instance credentials come
  from the app's admin screen or `occ config:app:get`, passed through env.
- Scripts that need a test hook use only the three dev switches and two env
  values of `08` §0.8; no other back door is added for a test.

### 0.5 Evidence — the STATUS walk entry

```text
**2026-MM-DD — <sprint> exit walk — <cloud> (<versions line from env-check>)**
Account: Ada (<cloud user>) · Bob where the journey needs him
L2: cf?-….sh PASS n/n, …
L3: J-CF-x ✓ / ✗ (what was seen; sentence shown; where it was found)
Fixed on the way: PR #…
Open: …
```

A screenshot or recording may be linked from outside the repo; it never
replaces the text.

### 0.6 Checks that apply to every sprint with a user-visible change

| Check | How |
| ----- | --- |
| **Light and dark** | Toggle the host theme (Nextcloud: Settings → Appearance; OpenCloud: theme switch; ownCloud: dark theme app if installed, else light only — say so) and Synaplan's theme; every new surface readable; glyphs ≥ 3:1 (DevTools contrast) |
| **Narrow** | 320 px for Synaplan surfaces (DevTools device toolbar); the host's narrowest supported width for the apps |
| **Five locales** | Switch the cloud UI language to `de`, `es`, `fr`, `tr`; every new string translated (no English fallback); the repo's locale-parity test is green |
| **Keyboard** | Every new button, menu entry and glyph tooltip reachable by Tab; `aria-label` read by the browser's accessibility tree |
| **No console errors** | DevTools console empty on the walked pages |
| **Flag off / older core** | With `PLATFORM_LINKS.ENABLED=0` (and `CLOUD_FILES_ENABLED=0` from CF4) the new surfaces are absent, old ones work (J-CF-12 rows below) |

---

## 1. CF0 — Fix and align

Preconditions: E-1…E-4 done (E-5 may still be open; ownCloud.online walks on
the host install are recorded as such). Stack: default Synaplan,
Nextcloud, ownCloud.online, OpenCloud with `--profile oidc`.

### L1

`make ci-local` in `synaplan`; in particular `ApiKeyScopeTest` with the new
contract fixture (CF0.1), the `FileController` upload tests for `owncloud`
and `intake=direct` (CF0.2, CF0.3), the `check-stale` test (CF0.4).

### L2

| Script | Asserts |
| ------ | ------- |
| `cf0-scopes.sh` | Mint a key with scopes `chat files rag` through the API-keys endpoint the apps' provisioning path uses. For every path in `backend/tests/Fixtures/cloud_apps_paths.json`: the response is **not** `403 insufficient_scope` (200, 400 or 422 are fine — the fixture carries a minimal valid body per path). For the neighbours `POST /v1/messages`, `POST /v1/images/generations`, `GET /api/v1/admin/users`: **403**. Repeat with a key `files rag` only: `/api/v1/summary/generate` **403** |
| `cf0-sources.sh` | Upload `spec.pdf` with `source=owncloud`, `source_id=oco-1`: stored with `BSOURCE=owncloud`, listed under the Library source filter "ownCloud.online", `check-stale` finds it by `source_id`. Upload with `source=nextcloud`, `group_key=<knowledge folder>`, `intake=direct`: `BINCOMING=0`; the same without `intake=direct`: `BINCOMING=1` (C10). `check-stale` response items carry `vector_state` |
| `platform-links/fake-instance.sh` and `--flag-off` | unchanged and green (regression) |

### L3 — CF0.7 first walks

| # | Cloud · mode | Do | Expect |
| - | ------------ | -- | ------ |
| 1 | Nextcloud · link (J-NC-1) | Settings → Synaplan → Connect → sign in as Ada → confirm | "Connected as Ada"; Apps → Nextcloud card shows the link |
| 2 | Nextcloud · link | Right-click `spec.pdf` → Synaplan → Summarize; Translate to German; chat about the file; generate an image from the chat | Four results, none says "insufficient scope" or "Request failed"; the image is saved under `Synaplan/Images` |
| 3 | Nextcloud · provision (admin creates Ada's account) | Same four actions as `ada` | Same results |
| 4 | ownCloud.online · provision | Settings: provision mode with the admin key; as `ada`: Summarize, Translate, chat, knowledge dialog on `spec.pdf` → Add → reopen | Dialog shows the real state ("Searchable" after adding); Library labels the file "ownCloud.online" |
| 5 | OpenCloud · organisation login | Log in as `ada` (Keycloak) → `spec.pdf` → Synaplan → Summarize; Knowledge → Add | Summary shown; Synaplan Library lists the file with source OpenCloud; in Synaplan, the file is **not** in Incoming when a knowledge folder was named in the dialog (D3) |
| 6 | all three | D3 negative: add a file without naming a knowledge folder | It is in Incoming (unchanged behaviour) |
| 7 | docs | Open `docs/plugins.md` (OpenCloud section) and `docs/intro.md` next to the OpenCloud UI | Every sentence matches what was seen in rows 5–6 |

Exit entry per cloud (0.5). CF0 is never cut; a red row is a blocking
defect.

---

## 2. CF1 — Parity floor

Preconditions: CF0 exit; E-5 green (ownCloud.online Docker stack); the three
app CIs exist after CF1.2 / CF1.3.

### L1

Each app repo's gate; locale-parity tests in all three repos (CF1.4); the
ownCloud.online PHPUnit suite mirrors Nextcloud's list of test classes
(CF1.2 brief names them); OpenCloud Go + Vitest + Playwright.

### L2

| Script | Asserts |
| ------ | ------- |
| `cf1-locales.sh` | For each app repo: the five locale files have the same key set; the core terms of `00` §11 appear verbatim ("Searchable by AI", "Make searchable by AI", …) in each locale's file; no banned word of `00` §11 appears in any locale file (list in the script) |
| `cf1-consent.sh` (ownCloud.online) | With consent not given: `POST /apps/oco_synaplan/api/summarize` (the app's route) answers the consent-required code; after `POST …/consent`: 200. Each of the four actions declares a MIME list (D6) — the route for an unsupported type answers 415 or the app's documented refusal |
| `cf1-keys.sh` (Nextcloud, ownCloud.online) | After linking Ada: `occ config:user:get ada synaplan_integration api_key` (resp. `oco_synaplan`) is **not** the plaintext key (encrypted prefix per the brief); a stored plaintext value from before the release is read once and re-saved encrypted (migration test: set a plaintext value with `occ`, call one action, read again) |

### L3

| # | Cloud | Do | Expect |
| - | ----- | -- | ------ |
| 1 | ownCloud.online (J-CF-2) | Settings → Synaplan → **Connect Synaplan** → sign in as Ada → "Connect ownCloud.online at localhost:8083 as ada?" → confirm | "Connected as Ada"; Synaplan Apps → ownCloud.online card shows it; the next Summarize runs as Ada (the run appears in Ada's Synaplan chats) |
| 2 | ownCloud.online | Disconnect in Synaplan → back to the cloud | Settings show **Connect Synaplan** again; a file action shows the Connect card, not an error |
| 3 | ownCloud.online | First AI action after connecting | The consent card (W1 sentence) appears once; declining leaves the file untouched and says so |
| 4 | ownCloud.online | Right-click a `.zip` | No Synaplan actions offered (MIME filter); a `.pdf` offers all four |
| 5 | ownCloud.online | Every page | The chat launcher is present (parity with Nextcloud) |
| 6 | Nextcloud | Re-walk J-NC-1 after the key encryption release | Existing link keeps working without re-connecting (C2) |
| 7 | all three | Switch the UI language to `de`, `es`, `fr`, `tr` | Consent card, Connect card, action names, dialog titles translated; "Make searchable by AI" wording (not "Add to AI Knowledge") in every locale |
| 8 | OpenCloud (CF1.5, if in this sprint) | Chat about `spec.pdf`; research chat; generate an image; **Save to Files** | Streamed answer; image saved as a binary file under `Synaplan/Images` (open it — not a text file) |
| 9 | Nextcloud, OpenCloud | Run the app's Playwright suite against the dev stack | Green; the J-NC-1 and Summarize specs exist (CF1.3) |

Exit: the `01` §5.2 gap matrix has no unexplained cell below Nextcloud 1.6;
five bullets of `06` §3 ticked per cloud.

---

## 3. CF2 — One identity

Preconditions: CF1 exit; `--profile oidc`; E-3 users; the OpenCloud app
registered as a platform instance (CF2.7); Nextcloud `user_oidc` configured
(`NC_OIDC=1`) when CF-S2 said yes.

### L1

`ApiKeyScopeTest` (`cloud:watch`, `/api/v1/cloud/*` map); handshake-style
tests for `sso/bind` (wrong `azp`, unknown instance, revoked identity, replay);
canonical rows and adoption (`02` §3.1: with and without the instance
credential, someone else's key, wrong server id, replay); lifecycle tests
(deleted / disabled / enabled) with the deletion policy (each keep-condition;
the admin delete with an API key → 409 — C18); instance-scoped file lookups
(C19); `OidcBearerAuthenticator` attribution test (`BLASTSEEN`,
`403 platform_disconnected`); migration review (C11).

### L2

| Script | Asserts |
| ------ | ------- |
| `cf2-identity.sh` | Register a fake instance with `client=opencloud` (as `fake-instance.sh` does), `PATCH instances/self` with delivery URL and `oidc_client_id=opencloud`; `GET instances/self` lists `capabilities` ⊇ `{sso_bind, lifecycle}`. Get a token for `ada` from Keycloak (`opencloud` public client, password grant — enabled in dev), exchange it for audience `synaplan-app` through `synaplan-opencloud`; `GET sso/status` → `bound=false`; `POST sso/bind` → 200, identity row `mode=sso`; `sso/status` → `bound=true`. Bind with a token whose `azp` is `synaplan-app` → **403**. `POST sso/background-key` → key with scopes exactly `files rag cloud:watch`; the key appears in Ada's API keys with the linked-platform badge (API list). `POST lifecycle {external_id: ada, event: disabled}` → identity `status=paused`; `enabled` → `active`; `deleted` → `revoked`, the background key answers 401. **Adoption block:** a second fake instance (`client=nextcloud`, `PATCH instances/self {server_id}`); provision `p1` with the admin key **and** that instance's credential → row `(nextcloud, <instance>, p1)`, `mode=provision`, the minted key bound; provision `p2` the old way (`external_id: <server_id>:p2`, no instance credential) → legacy row; `POST identities/adopt` with p2's key → canonical row, key bound; the same call with p1's key for `p2` → **403**. **Deletion block:** `lifecycle {external_id: p2, event: deleted}` → p2's account is gone; provision `p3` with a password (test tooling) → `deleted` revokes the identity and keeps the account (`platform_link.account_kept` in the audit log); the generic admin delete for p3 with the admin API key → **409** `account_in_use` |
| `cf2-file-instance.sh` | Two fake `nextcloud` instances, one Synaplan user linked on both (two keys). Upload `spec.pdf` with `source_id=5678` through each key → two `BFILES` rows with different `BSOURCEINSTANCE`; `overwrite=1` through key A replaces only A's row; `check-stale` through key B answers B's etag; an upload with the person's own API key (no identity) and `source_id=5678` matches neither (C19) |
| `cf2-apps-card.sh` | `GET /api/v1/me/platform-links` as Ada lists the OpenCloud row with `mode`, `status`, `last_seen`; `POST …/{id}/turn-off` (the endpoint CF2.5 names) → the next bearer call from the instance answers `403 platform_disconnected`; `…/resume` → 200 again |
| `cf2-flag-off.sh` | `disable_flag`; every new route (`sso/*`, `identities/adopt`, `lifecycle`, `read-scope`, `me/platform-links/{id}/resume`) answers **404**; `enable_flag` |

### L3

| # | Cloud | Journey | Expect |
| - | ----- | ------- | ------ |
| 1 | OpenCloud | J-CF-1 | First Summarize shows the consent card with "Signed in as ada@example.org"; **Use Synaplan** → summary; Synaplan → Apps → OpenCloud: "Connected through your organisation login · last used just now" |
| 2 | OpenCloud | J-CF-1 turn off | **Turn off for OpenCloud** shows its consequence sentence; the next Summarize in OpenCloud says "You turned Synaplan off for this app in Synaplan." with **Open Synaplan**; **Turn back on** restores |
| 3 | Nextcloud (CF-S2 yes) | J-CF-1 | Log in to Nextcloud through Keycloak as `ada`; first Synaplan action → consent card → works without any Connect; admin screen shows the four modes (W8) with **Check organisation login** green |
| 4 | Nextcloud | local admin without OIDC session | Sees the Connect card, not an error |
| 5 | ownCloud.online | J-CF-2 re-walk; admin screen | Organisation login row reads "not available on ownCloud.online" |
| 6 | all three | J-CF-11 identity half | Disconnect in Synaplan → the cloud shows Connect / "turned off" within one action; reconnect works |
| 7 | all three | Cloud admin disables `ada` (Nextcloud: `occ user:disable ada`) | Synaplan Apps card: "Paused — your Nextcloud account was disabled"; re-enable → active again |
| 8 | Synaplan | Admin → People → Platform instances | The OpenCloud instance is listed with mode counts; approving a private delivery address shows the dialog that says it becomes an allowlist entry |
| 9 | all three | J-CF-12 identity part: point the app at a core with `PLATFORM_LINKS.ENABLED=0` | Bind and consent changes hidden; summarize still works in shared mode; one info line in the server log, no error toast |
| 10 | Nextcloud and ownCloud.online (provision in one, link in the other) | delete a person who also uses Synaplan elsewhere | The account one cloud provisioned for Ada gets a password (forgot-password mail in MailHog) and connects the other cloud; the first cloud's admin deletes `ada` → the other cloud keeps working as Ada, the Synaplan account stays, Synaplan → Apps no longer lists the first cloud for her. Walked once in each direction (C18) |

---

## 4. CF3 — Files both ways

Preconditions: CF2 exit; scheduler running (sweeps); the three apps
registered with delivery URLs reachable from the `backend` container
(`http://nextcloud/apps/synaplan_integration/api/v1/inbound`, the ownCloud
and OpenCloud equivalents).

### L1

Copy protocol tests (C4: replay, expiry 5 min, wrong instance, wrong user);
notify signing and rotation (core) and each app's verifier — forged,
replayed, stale and other-instance notifications → `401` with no outbound
call (C20); `DestinationResult::pending()` only from the `platform` provider;
`PlatformDestinationProvider` unit tests; pickup ticket tests (C16);
`FolderFetchRunner` tests (list vs fetch, attachments on the run message,
fail-closed without files — C15); `WebDavClient::list()` / `get()` against a
local WebDAV fixture; read-scope pre-check tests; OpenAPI → generated schemas →
`vue-tsc`.

### L2

| Script | Asserts |
| ------ | ------- |
| `cf3-copy.sh` (`CLOUD=…`) | As Ada: upload `chart.png`; `POST /api/v1/files/{id}/send {destination: platform, identity_id, target: kind_folder}` → 200 within 30 s with `remote_path = Synaplan/Images/chart.png` and an `open_url`; the cloud helper finds the file as Ada; send again → `chart (2).png`; `db_query` shows two `BCLOUDDELIVERIES` rows `delivered`. Stop the cloud container; send → the failure code `unreachable` and the sentence "Synaplan could not reach …. Nothing was copied."; no ledger row left `queued`. Start the cloud; set the app's `dev_hold_inbound` switch (`08` §0.8); send → **202** `pending`; with `CLOUD_DELIVERY_UNCONFIRMED_SEC=60` on the backend the sweep marks the row `unconfirmed` within 90 s and the person's "Sent to" shows the sentence; clear the switch |
| `cf3-ticket.sh` | Claim a delivery id twice → second **409/410**; claim with another instance's credential → **403**; claim after expiry → **410** (backend started with `CLOUD_DELIVERY_TTL_SEC=5`) |
| `cf3-inbound.sh` (`CLOUD=…`) | Send the app's inbound URL 1 000 notifications that must fail — no `sig`, signed with another key, another `instance_id`, `ts` ten minutes old, a replay of a valid one — every answer is **401** (or **429** once the per-address limit hits), and the backend log shows no claim call from that instance meanwhile (C20); then one real copy → claimed and `delivered` |
| `cf3-pickup.sh` (`CLOUD=…`) | Put `invoice-0912.pdf` and `invoice-1003.pdf` in Ada's `Invoices` (helper); set Ada's read scope in the cloud to **Only folders I watch** (helper `occ` / API) — then a task with one `folder_fetch` step (`folder: Invoices`, `pattern: *.pdf`) run through the Saved Tasks API ends `failed` with the sentence "Synaplan may not read Invoices in your …. Nothing was read."; set **Also folders I allow** + allow `Invoices` → the run's message has two attachments with the right names; `BCLOUDPICKUPS` row `done`, `listed=2`, `sent=2`; run again → `sent=0` (versions reused); add `big-101mb.bin` → `partial`, skipped lists it with reason size; 21st pickup in the hour → the limit sentence |
| `cf3-webdav.sh` | `folder_fetch` on a plain WebDAV connection (the Synaplan dev WebDAV fixture) lists and fetches without a pickup |
| `cf3-flag-off.sh` | `disable_flag`: `GET /api/v1/me/platform-links` answers **404** (existing behaviour), so the Copy-to-cloud menu lists no linked clouds; `folder_fetch` is absent from the planner's capability list; claim / pickup / read-scope routes **404**; `enable_flag` |

### L3

| # | Cloud | Journey | Expect |
| - | ----- | ------- | ------ |
| 1 | all three | J-CF-3 | Library → Generated → chart → **Copy to cloud** → "Nextcloud · localhost:8081 → Synaplan/Images"; toast "Copied to Nextcloud › Synaplan/Images/chart.png" with **Open in Nextcloud** that opens the file; the cloud's bell shows the notification; **Sent to** on the file lists it |
| 2 | all three | J-CF-3 again | `chart (2).png`; **Sent to** lists both |
| 3 | all three | J-CF-3 unreachable | Cloud stopped: "Synaplan could not reach …. Nothing was copied." + the From-Synaplan recovery sentence; no "running" state anywhere |
| 4 | all three | J-CF-4 | In `Projects/Q3`: **New → From Synaplan…** → Generated first → pick two → **Add 2 files** → both appear; empty picker shows "Files Synaplan makes for you appear here." + **Open Synaplan** |
| 5 | Nextcloud, ownCloud | personal setting | "Save files from Synaplan to" with the native picker → the next copy lands there, under the kind sub-folder |
| 6 | all three | app chat Save to Files | Document, audio and calendar kinds save (Nextcloud, ownCloud); binary image saves on OpenCloud |
| 7 | all three | J-CF-14 | Chat: "Take the PDFs in my Nextcloud folder Invoices and make one Excel table of vendor, date and total." → card "Read 2 files from Nextcloud › Invoices" with chips → the table file → **Copy to cloud** works on it |
| 8 | all three | J-CF-14 refused | Ask for `HR/Contracts` → "Synaplan may not read HR/Contracts in your Nextcloud. Nothing was read." + **Open in Nextcloud**; there, right-click → **Let Synaplan read this folder**; ask again → works; the Apps card reads "Synaplan may read: the folders you watch, HR/Contracts" |
| 9 | all three | read setting (W11) | Personal settings show the three choices; **All my files** shows the warning sentence; the admin cap hides choices above it |
| 10 | Synaplan | task run card (W9) | A task with `folder_fetch` shows "Reading files from Nextcloud… n of m" while running and the final sentence; the run names every file read and every file skipped |
| 11 | Nextcloud, OpenCloud | cross-cloud | Copy a file that was picked up from OpenCloud to Nextcloud; **Sent to** names Nextcloud; the OpenCloud original is untouched |
| 12 | all three | stability | Restart the cloud container during a copy of a 50 MB file → the ledger row ends `delivered` or `failed` within 10 min, never `queued`; restart `backend` during a pickup → the run ends with a sentence (partial or failed) |

---

## 5. CF4 — Searchable state

Preconditions: CF3 exit; `CLOUD_FILES_ENABLED=1`; source events flowing from
each app (Nextcloud node events, ownCloud hooks, OpenCloud event bus or
reconcile-only per CF-S3).

### L1

`POST /api/v1/cloud/status` tests (states per `04` §A.1, cache ≤ 60 s,
batch of 500 ids); `sources/events` tests (changed → `changed`, deleted →
hidden + `BSOURCEDELETEDAT`, restored → cleared, unknown id ignored);
reaper test for the 7-day grace; FeatureModule `cloud_files` gate (404 when
off, absent from runtime config); glyph kit snapshot tests; runtime-config
override field.

### L2

| Script | Asserts |
| ------ | ------- |
| `cf4-status.sh` (`CLOUD=…`) | Upload `spec.pdf` from the cloud as searchable (source id `s1`, etag `e1`) → `POST /api/v1/cloud/status {ids:[s1, s2]}` → `s1: searchable`, `s2: none`; change the file in the cloud (helper re-uploads with new content) → within 90 s `changed` then `updating` then `searchable` (poll); trash it → the file disappears from `GET /api/v1/files` and from a RAG query, `BSOURCEDELETEDAT` set; restore → back without a new `BFILES` row; after a simulated 7 days (dev reaper command with a date override named in the brief) the row is gone |
| `cf4-status-perf.sh` | `many/` (5 000 files) in Ada's folder; `cloud/status` for a page of 500 ids: p95 < 1.5 s over 10 calls; second call within 60 s served from cache (`X-Synaplan-Cache: hit`) |
| `cf4-flag-off.sh` | `CLOUD_FILES_ENABLED=0` → `cloud/status` **404**; `GET /api/v1/config/runtime` has no `cloud_files` block; `instances/self.capabilities` lacks `cloud_status` |

### L3

| # | Cloud | Journey | Expect |
| - | ----- | ------- | ------ |
| 1 | all three | J-CF-5 | Right-click `spec.pdf` → **Make searchable by AI** ("Keep it up to date" on) → bird with clock → bird (Nextcloud, ownCloud in the row; OpenCloud in the sidebar unless CF-S1 found a row point); tooltip "Searchable by AI — in “Nextcloud · Specs”" |
| 2 | all three | edit | Edit the file in the cloud → bird with arrows → bird within two minutes; nothing to click |
| 3 | all three | sidebar | The "Synaplan" tab answers the five questions: who made it searchable, who else can ask about it, what it touches (knowledge folder), how to stop (**Remove from AI** button), where it came from |
| 4 | all three | delete and restore | Delete → gone from Synaplan answers and Library at once; restore from trash → bird back, no new upload in the Library history |
| 5 | all three | Bob | Bob opens the shared `Specs` → no bird on files he did not add (v1 shows *my* state) |
| 6 | all three | failure | Stop Synaplan → file list shows one quiet line ("Synaplan is not reachable") and no broken glyphs; start it → glyphs return on the next navigation |
| 7 | all three | flag off / older core | `CLOUD_FILES_ENABLED=0` → no glyph, no sidebar tab, no console error (J-CF-12) |
| 8 | all three | themes | Glyph contrast ≥ 3:1 in the host's light and dark themes (measured); the white-label override in runtime config replaces both glyphs |

---

## 6. CF5 — Watched folders and automations

Preconditions: CF4 exit; `cron` containers running (Nextcloud, ownCloud);
scheduler running; `--compute` for J-CF-13; Ada's cloud key carries
`cloud:watch` after the permission card.

### L1

Watch API tests; ledger unique key (C6); loop guard three layers (C7);
file targets (only new versions of that file; deletion and restore); watch
index paging, `since` with tombstones, `304`, `410`; caps (50 per person,
5 000 per instance); quiet period and windowing (`BWINDOWSEC`, `BMAXFILES`)
with a fake clock;
`file_event` trigger payload (`files`, `set`) and `SavedTaskRunner` attaching
files (C15 fail-closed); presets → task templates; trial and `from-message`
tests (pinning rejects a script that names the test file — C17);
`SavedTaskComputePauseTest` family for unattended file work; lifecycle
effects on watches (C9); sweeps for stuck items (30 min); mail template
render test (4–5 sentences, two links, plain-text part).

### L2

| Script | Asserts |
| ------ | ------- |
| `cf5-watch.sh` (`CLOUD=…`) | Create a **Summarize and mail me** watch on `Reports` through `POST /api/v1/cloud/watches` (Ada's key with `cloud:watch`); the row is `active`; upload `report.pdf` version `v1` to `…/watches/{id}/files` → one `BCLOUDWATCHITEMS` row → run → MailHog has one mail "Summary: report.pdf" with 4–5 sentences (count sentences in the plain-text part), **Open in …** and **See this run in Synaplan** links; upload `v1` again → "already handled", no second run or mail (C6); `PATCH {state: paused}` → upload `v2` → no run; resume → the resume answer lists 1 waiting file → run; `DELETE` → row gone, the task is deleted with it, the item ledger rows deleted first (C11 order). **File block:** a Translate watch with `target_kind: file` on `Projects/Q3/price-list.docx` — a new version → one run and `price-list.en.docx`; a second new version → the output is replaced, not duplicated (copy-ledger remote id); another file in `Projects/Q3` → no run; trash the file → the watch reads `attention: target_gone`; restore → `active` |
| `cf5-index.sh` | Seed 5 000 watches over 100 test identities on a fake instance (dev command); `GET platform-links/watches?limit=500` → 10 pages, each ≤ 500, no watch twice; change one watch → `since=<cursor>` returns exactly that one; stop one → a tombstone; nothing changed → **304**; an expired cursor → **410**; the 51st watch of one person and the 5 001st of the instance → **409** with the sentences of `04` §B.7 |
| `cf5-quiet.sh` | Upload `v1`, `v2`, `v3` of the same file within 40 s → one run (for `v3`) after the 60 s quiet period; the server clock is read inside the container |
| `cf5-loop.sh` | With the app's `dev_allow_self_write` switch (`08` §0.8): a **Translate** watch on `Incoming-DE` writing into `Incoming-DE` itself: one run produces `contract.en.docx`; the app's own-write guard and the copy-ledger lookup leave it unprocessed; no second run within 5 min (C7) |
| `cf5-reconcile.sh` | Set the app's `dev_mute_events` switch; upload two files; `POST …/watches/{id}/reconcile` with the folder listing → the answer names the two ids to process → runs; a listing without a known id → the answer says to forget it and the item is `skipped: gone` |
| `cf5-limits.sh` | 21 uploads to a Summarize watch in one hour → 20 mails, the row's `counts.waiting = 1` and the sentence "1 file waiting until <next hour>"; a 101 MB upload → `skipped: too_large` with the sentence |
| `cf5-fileset.sh` | Watch with `file_set: together`, `window_sec: 60`, task "merge CSVs" (no file work): upload the three CSVs within 30 s → exactly one run after the window, three attachments, payload `set.count = 3`; `file_set: whole_folder`: `POST …/snapshots` with the listing → answer lists only versions Synaplan lacks → `…/close` → one run with the folder's files; a snapshot of 312 files → refused with the "at most 50" sentence |
| `cf5-trial.sh` | `POST /api/v1/cloud/trials` with `invoice-0912.pdf` + instruction → chat URL; the chat's first message has the attachment; `POST /api/v1/cloud/watches/from-message/{id}` → a watch with a task whose steps equal the trial turn's pinned steps; `file_set` and `allow_unattended` as sent; a pinned file-work script containing `invoice-0912.pdf` → **422** with the C17 sentence |
| `cf5-lifecycle.sh` | Disconnect Ada's identity → both watches `paused` with reason `disconnected`; upload → no run; reconnect → `GET …/watches` returns the resume question data (`paused_count = 2`); resume → files that arrived meanwhile are handled |
| `cf5-flag-off.sh` | `CLOUD_FILES_ENABLED=0` → watch routes **404**; existing watches keep their rows; `instances/self.capabilities` lacks `watches`; the app hides the Watch menu (J-CF-12) |

### L3

| # | Cloud | Journey | Expect |
| - | ----- | ------- | ------ |
| 1 | all three | permission card | First **Watch with Synaplan…** shows "Let Synaplan watch folders for you?" once; after confirming, the key carries `cloud:watch` (API keys list) |
| 2 | all three | J-CF-6 | Watch `Specs` → **Keep them searchable** → "Also the 7 files already in this folder" (on) → **Start watching**; eyes on `Specs`; birds appear; Tasks → **Watched folders** row "Specs · Nextcloud · 7 searchable"; Library knowledge folder "Nextcloud · Specs" with "Kept in sync with Nextcloud › Specs"; a chat answer cites a Specs file; new file → bird within two minutes; **Stop syncing** → eyes gone within a minute, files stay searchable |
| 3 | all three | J-CF-7 | Translate watch → drop `contract.docx` → `Incoming-EN/contract.en.docx` after the quiet period; row "Last: … · 1 translated" with **Open in …**; same version again → nothing; Pause → drop → nothing; Resume → "Also handle the 1 file that arrived while paused?" → translated; `contract-scan.pdf` → "contract-scan.pdf has no readable text. Nothing was translated." + a cloud notification |
| 4 | all three | J-CF-8 | Summarize watch → `report.pdf` → mail with 4–5 sentences, **Open in …**, **See this run in Synaplan**, readable at 360 px in MailHog's preview; 30 files → 20 mails this hour, the row says "10 files waiting until <hour>" |
| 5 | all three | J-CF-9 | **Run my instruction** → **Try it on a file** → trial chat with the invoice attached and a table; refine; **Do this for every new file in “Invoices”** → **Mail me**; new invoice → mail with *that* invoice's table; switch to **Save next to the file** → `invoice-1003.answer.md` appears; **Edit in Synaplan** opens the Steps editor; "Ask me before this step" → the next file waits in Approvals |
| 6 | all three | J-CF-10 | Bob opens the shared `Incoming-DE`: eyes; sidebar "Ada's Synaplan watches this folder: …. Only Ada can change this."; no Pause / Stop for Bob; Ada stops → Bob's eyes gone within a minute; admin turns "Allow watching shared folders" off → Ada's dialog offers no shared folder; the existing watch reads "Stopped — your administrator turned off watching shared folders" |
| 7 | all three | J-CF-11 full | Disconnect → Connect shown in the cloud; both watches "Paused — … is disconnected from your Synaplan account" on both sides; drop a file → not processed; reconnect → "Resume 2 watched folders?" → **Resume** → handled |
| 8 | all three | J-CF-12 | App against a core with `CLOUD_FILES_ENABLED=0`: no Watch menu, no eyes, no console error |
| 9 | all three (`--compute`) | J-CF-13 | **New files together** + instruction with file work → **Try it on files** with three CSVs → trial shows the file-work card and `revenue.xlsx` with a chart; **Do this for every new file** → "This task does file work. Let it run without asking each time?" → **Yes**; three CSVs dropped → one run after the window with three attachments → `revenue.xlsx` next to them; the run names three inputs and one output; with **Ask me each time** the run waits in Approvals: "Run file work on 3 files from Exports?"; on a stack without Compute the dialog never mentions file work |
| 10 | Nextcloud, ownCloud | cron warning | Set `occ background:ajax` → the admin screen shows the cron warning; the watch dialog says watches need background jobs; back to `cron` clears it |
| 11 | Synaplan | Watched folders tab (W7) | Empty state sentence + action when Ada has no watch; rows with cloud, folder, preset, last run, counts; Pause / Resume / Stop from the row with the kind-specific consequence sentence; run detail shows the files with **Open in …** |
| 12 | all three | stability | Kill the `cron` container during a run → the item ends `failed` with a sentence within 30 min (sweep), never "running"; stop Synaplan for 5 min while files arrive → the app's job list retries; after start, every file has one run |
| 13 | all three | cost (C14) | Each handled file is one task run in Ada's usage; at the plan limit the row says "Your Synaplan limit for today is reached — n files wait until tomorrow." |
| 14 | all three | J-CF-15 | Watch the single file `Projects/Q3/price-list.docx` → **Translate it**; the dialog asks "What should happen when this file changes?" and has no file-set block; the row shows the bird with eyes; a new version replaces `price-list.en.docx`; another file in `Projects/Q3` starts nothing; Tasks → Watched folders lists it as a file; Pause / Resume from the sidebar; delete → "Stopped — the file was deleted"; restore → watching; **Stop watching** → eyes gone, the translation stays |

After the exits: `cloud_files` on for new installs and the activation
migration (decision 22) — verified by a fresh install (`docker compose down -v`
in a scratch checkout) showing the flag on.

---

## 7. Regression set — re-run after every sprint

| Suite | Command |
| ----- | ------- |
| Synaplan gates | `make ci-local && make test-e2e` (+ `make -C frontend test-e2e-layout` when nav or layout changed; `npm run test:e2e:oidc` when auth changed) |
| Platform links | `_devextras/testing/platform-links/fake-instance.sh` and `--flag-off` |
| Cloud apps L2 | every `cf*.sh` of the sprints finished so far, once per cloud (`CLOUD=nextcloud|owncloud|opencloud`) |
| Nextcloud app | `make lint && make test`; Playwright against `make dev-up` (from CF1.3); on both matrix images (`NC_IMAGE`) |
| ownCloud.online app | `make lint && make check-types && make test` (from CF1.2); L3 checklist of the last sprint by hand |
| OpenCloud app | `make frontend-lint frontend-typecheck frontend-test-unit backend-test backend-lint && make frontend-test-e2e-dev` |
| Mobile class | `node scripts/mobile-impact.mjs --base main --head HEAD` in `synaplan` (then delete `mobile-release-manifest.json`) |
| Environment | `env-check.sh --cloud all --oidc --compute` |
