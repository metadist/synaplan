# Functional tests after each sprint

A sprint is finished when its three test layers are green **and** its walks
are dated entries in [`STATUS.md`](./STATUS.md). This file says, per sprint,
exactly what runs, what is clicked, and what must be seen. The briefs in
[`08_agent_briefs.md`](./08_agent_briefs.md) name the unit tests; this file
owns everything that needs a running stack.

**Per phase** (`00` row 42). Each sprint section runs once per phase, for that
phase's cloud: P1 with `CLOUD=nextcloud` plus the reference client in both
profiles (`CLOUD=fixture PROFILE=keyed|sso`), P2 with `CLOUD=opencloud`, P3
with `CLOUD=owncloud`. "Each cloud" in a table means the phase's cloud. Core
rows (the Synaplan side) are green from P1 on and re-run as regression in P2
and P3. The phase exits are §8.

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

`env-check.sh` (`10` §5) is green before an L2 or L3 run, with the phase's
profile: P1 `--cloud nextcloud --oidc` (Keycloak is needed for the `sso`
reference profile and CF2.6), plus `--cloud opencloud` for the compatibility
check; P2 `--cloud opencloud --oidc`; P3 `--cloud owncloud --oidc` (the `sso`
reference profile runs in every phase's regression); `--compute` for
J-CF-13. The STATUS entry copies its `versions` block.

| Role | Synaplan account | Nextcloud | ownCloud.online | OpenCloud / Keycloak | Used for |
| ---- | ---------------- | --------- | --------------- | -------------------- | -------- |
| **Ada (linked)** | `demo@synaplan.com` / `demo123` (seeded) | `ada` | `ada` | — | Connect your Synaplan account; owner of files and watches in the link mode |
| **Ada (organisation)** | `ada@example.org` — created by her first organisation login; a different Synaplan account from the one above | `ada` with `user_oidc` (CF2.6) | — | `ada` (Keycloak) | Organisation-login journeys (J-CF-1, the `sso` reference profile) |
| **Carol (provisioned)** | one account per cloud, created by its provisioning, never used with Keycloak: `carol@example.org` (Nextcloud), `carol.oco@example.org` (ownCloud.online) — Synaplan refuses to provision an email that already exists | `carol` | `carol` | — | Accounts created by the administrator (CF0.7 row 3, C18 walks) |
| **Bob** — colleague with a share, no Synaplan link | — (J-CF-10 is the point: Bob has none) | `bob` | `bob` | `bob` | Shared-folder journeys |
| **Cloud admin** | — | `admin` | `admin` | `admin` | Admin screens |
| **Synaplan admin** | seeded admin | — | — | — | Approvals, instance list |

Why two Adas and a Carol: Synaplan refuses an organisation login for an email
that already belongs to another account (strict isolation in
`OidcUserService`), so provisioning `ada@example.org` would break her
organisation login. Each journey row names the account it uses. Mail goes to
MailHog (`http://localhost:8025`). A walk with other accounts is recorded as
such.

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
- **Deterministic AI.** L2 scripts switch the default models to the
  `TestProvider` the way the E2E suite does
  (`frontend/tests/e2e/global-setup.ts`) and restore them at the end, so
  answers are stable. They assert structure — a mail exists, its subject, both
  links, a non-empty summary, an output file with the expected name — never
  the wording of a model answer. Answer quality (4–5 sentences, a correct
  table) is an L3 walk with a real model.
- **The reference client** (`CLOUD=fixture`, E-9) answers like an app:
  `PROFILE=keyed` (per-user keys, numeric ids, bare etags) and `PROFILE=sso`
  (Keycloak bearer and background key, ids `storage$space!node` of about 110
  characters, quoted etags). Every core-only assertion runs against both
  profiles before an app exists.

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
| **App lacks the feature** | A reference-client instance that reports no `app_capabilities`: Synaplan offers it nowhere — no copy target, no folder channel, no watch hint, no feature line (C21) |

---

## 1. CF0 — Fix and align

Preconditions (P1): CF0.0 — E-1, E-2, E-3, E-6 green; E-4 for the
OpenCloud compatibility rows; the ownCloud.online compatibility rows run on
the host install (`:8088`) and the STATUS entry says so. Stack: Synaplan with
`--profile oidc`, Nextcloud, OpenCloud.

### L1

`make ci-local` in `synaplan`; in particular `ApiKeyScopeTest` with the new
contract fixture (CF0.1), the upload tests for `owncloud` (CF0.2) and for the
knowledge-folder rule (CF0.3), the `check-stale` test (CF0.4).

### L2

| Script | Asserts |
| ------ | ------- |
| `cf0-scopes.sh` | Mint a key with scopes `chat files rag` through the API-keys endpoint the apps' provisioning path uses. For every path in `backend/tests/Fixtures/cloud-apps-contract/paths.json`: the response is **not** `403 insufficient_scope` (200, 400 or 422 are fine — the fixture carries a minimal valid body per path); this includes `GET /api/v1/config/memory-service/check`. For the neighbours `POST /v1/messages`, `POST /v1/images/generations`, `GET /api/v1/admin/users`: **403**. Repeat with a key `files rag` only: `/api/v1/summary/generate` **403** |
| `cf0-sources.sh` | Upload `spec.pdf` with `source=owncloud`, `source_id=oco-1`: stored with `BSOURCE=owncloud`, listed under the Library source filter "ownCloud.online", `check-stale` finds it by `source_id`. Upload with `source=nextcloud`, `group_key=<a knowledge folder>`: `BINCOMING=0`; with `group_key=DEFAULT` and without `group_key`: `BINCOMING=1`; with `source=outlook` and a named folder: `BINCOMING=1` (C10). `check-stale` response items carry `vector_state` |
| `platform-links/fake-instance.sh` and `--flag-off` | unchanged and green (regression) |

### L3 — CF0.7 first walks

| # | Cloud · mode | Do | Expect |
| - | ------------ | -- | ------ |
| 1 | Nextcloud · link (J-NC-1) | As `ada`: Settings → Synaplan → Connect → sign in as Ada (linked) → confirm | "Connected as Ada"; Apps → Nextcloud card shows the link |
| 2 | Nextcloud · link | Right-click `spec.pdf` → Synaplan → Summarize; Translate to German; chat about the file; generate an image from the chat | Four results, none says "insufficient scope" or "Request failed"; the image is saved under `Synaplan/Images` |
| 3 | Nextcloud · provision | Admin switches to Accounts created by the administrator; as `carol`: the same four actions | Same results; Synaplan has the account `carol@example.org` |
| 4 | Nextcloud · link (J-NC-2) | As the throwaway user `nc-temp` (created by the helper, deleted afterwards), connect with an email that already has a Synaplan account | "An account with this email exists — connect it" and the connect flow continues |
| 5 | Nextcloud (D3) | As `ada`, Add to knowledge with a named knowledge folder; then once with no folder | First file: in that folder, **not** in Incoming; second: in Incoming (unchanged behaviour) |
| 6 | Nextcloud · link (J-NC-3) | Disconnect in Synaplan, then reconnect; disconnect in Nextcloud, then reconnect | Each side returns the other to Connect; reconnecting works without an error |
| 7 | docs | Open `docs/plugins.md` (OpenCloud section) and `docs/intro.md` next to the OpenCloud UI | Every sentence matches what row 9 shows |
| 8 | ownCloud.online · provision (compatibility, host install) | As `carol`: Summarize, Translate, chat, knowledge dialog on `spec.pdf` → Add → reopen | Works as before; the dialog shows the real state ("Searchable" after adding); Library labels the file "ownCloud.online" |
| 9 | OpenCloud · organisation login (compatibility) | As Ada (organisation): `spec.pdf` → Synaplan → Summarize; Knowledge → Add with a named folder | Works as before; Synaplan Library lists the file with source OpenCloud, not in Incoming (D3) |

Exit entry per cloud (0.5). CF0 is never cut; a red row is a blocking
defect. In P2 and P3 the phase's cloud repeats the rows of its own column
(OpenCloud row 9, ownCloud.online row 8) on its E-4 / E-5 stack.

---

## 2. CF1 — Parity floor

Preconditions: CF0 exit for the phase. P1: the Nextcloud rows (6, 7, 9 and
the Nextcloud half of `cf1-keys.sh` / `cf1-locales.sh`); P2: the OpenCloud
rows (7, 8, 9); P3: E-5 green and the ownCloud.online rows (1–5, 7). Each
app's CI exists after its CF1 brief (CF1.3, CF1.2).

### L1

Each app repo's gate; locale-parity tests in all three repos (CF1.4); the
ownCloud.online PHPUnit suite mirrors Nextcloud's list of test classes
(CF1.2 brief names them); OpenCloud Go + Vitest + Playwright.

### L2

| Script | Asserts |
| ------ | ------- |
| `cf1-locales.sh` | For each app repo: the five locale files have the same key set; the core terms of `00` §11 appear verbatim ("Searchable by AI", "Make searchable by AI", …) in each locale's file; no banned word of `00` §11 appears in any locale file (list in the script) |
| `cf1-consent.sh` (ownCloud.online) | With consent not given: `POST /apps/oco_synaplan/api/summarize` (the app's route) answers the consent-required code; after `POST …/consent`: 200. Each of the four actions declares a MIME list (D6) — the route for an unsupported type answers 415 or the app's documented refusal |
| `cf1-keys.sh` (Nextcloud, ownCloud.online) | After linking Ada: `occ config:user:get ada synaplan_integration synaplan_user_api_key` (resp. the `oco_synaplan` preference that CF1.1 names) is **not** the plaintext key (encrypted prefix per the brief); a stored plaintext value from before the release is read once and re-saved encrypted (migration test: set a plaintext value with `occ`, call one action, read again) |

### L3

| # | Cloud | Do | Expect |
| - | ----- | -- | ------ |
| 1 | ownCloud.online (J-CF-2) | Settings → Synaplan → **Connect Synaplan** → sign in as Ada → "Connect ownCloud.online at localhost:8083 as ada?" → confirm | "Connected as Ada"; Synaplan Apps → ownCloud.online card shows it; the next Summarize runs as Ada (the run appears in Ada's Synaplan chats) |
| 2 | ownCloud.online | Disconnect in Synaplan → back to the cloud | Settings show **Connect Synaplan** again; a file action shows the Connect card, not an error |
| 3 | ownCloud.online | First AI action after connecting | The consent card (W1 sentence) appears once; declining leaves the file untouched and says so |
| 4 | ownCloud.online | Right-click a `.zip` | No Synaplan actions offered (MIME filter); a `.pdf` offers all four |
| 5 | ownCloud.online | Every page | The chat launcher is present (parity with Nextcloud) |
| 6 | Nextcloud | Re-walk J-NC-1 after the key encryption release | Existing link keeps working without re-connecting (C2) |
| 7 | each cloud | Switch the UI language to `de`, `es`, `fr`, `tr` | Consent card, Connect card, action names, dialog titles translated; "Make searchable by AI" wording (not "Add to AI Knowledge") in every locale |
| 8 | OpenCloud (CF1.5, if in this sprint) | Chat about `spec.pdf`; research chat; generate an image; **Save to Files** | Streamed answer; image saved as a binary file under `Synaplan/Images` (open it — not a text file) |
| 9 | Nextcloud, OpenCloud | Run the app's Playwright suite against the dev stack | Green; the J-NC-1 and Summarize specs exist (CF1.3) |

Exit: the `01` §5.2 gap matrix has no unexplained cell below Nextcloud 1.6;
five bullets of `06` §3 ticked per cloud.

---

## 3. CF2 — One identity

Preconditions: CF1 exit for the phase; `--profile oidc`; E-3 users. P1: the
reference client registered in both profiles (E-9), Nextcloud registered
(CF2.10), Nextcloud `user_oidc` configured (`NC_OIDC=1`) when CF-S2 said yes.
P2: the OpenCloud app registered as a platform instance (CF2.7). P3:
ownCloud.online registered (CF1.1).

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
| `cf2-identity.sh` | Blocks, each run by the brief named in brackets. **Registration** (CF2.1): register the reference client (`PROFILE=sso`) with `client=opencloud` and host `cloud-fixture`, and a second one with `PROFILE=keyed`, `client=nextcloud`; `PATCH instances/self` with `delivery_url=http://cloud-fixture:8099/inbound`, `app_capabilities=[]`; `GET instances/self` → `reachable: true`, `capabilities` present. **Bind** (CF2.2): `capabilities` ⊇ `{sso_bind, identity_adopt}`; get a token for `ada` from Keycloak (`opencloud` public client, password grant — enabled in dev), exchange it for audience `synaplan-app` through `synaplan-opencloud`; decode it and **record its `azp`** (expected `synaplan-opencloud`; if Keycloak puts another value there, STATUS records it and the instance registers that value); `PATCH instances/self {oidc_client_id: <recorded azp>}`; `GET sso/status` → `bound=false`; `POST sso/bind` → 200, identity row `mode=sso`; `sso/status` → `bound=true`; bind with a token whose `azp` differs (a direct `synaplan-app` token) → **403**. **Chat** (CF2.2): the exchanged token on `POST /v1/chat/completions` (TestProvider) → 200, on `GET /v1/models` → 200, on `POST /v1/messages` → **401** (D18). **Background key** (CF2.3): `POST sso/background-key` → key with scopes exactly `files rag cloud:watch`; the key appears in Ada's API keys with the linked-platform badge (API list). **Lifecycle** (CF2.4): `capabilities` ⊇ `{lifecycle}`; `POST lifecycle {external_id: ada, event: disabled}` → identity `status=paused`; `enabled` → `active`; `deleted` → `revoked`, the background key answers 401. Instance ids, external ids and remote ids of the `sso` instance use the long forms. **Adoption** (CF2.2): on the `keyed` instance (`PATCH instances/self {server_id}`) provision `p1` with the admin key **and** the instance credential → row `(nextcloud, <instance>, p1)`, `mode=provision`, the minted key bound; provision `p2` the old way (`external_id: <server_id>:p2`, no instance credential) → legacy row; `POST identities/adopt` with p2's key → canonical row, key bound; the same call with p1's key for `p2` → **403**. **Deletion** (CF2.4): `lifecycle {external_id: p2, event: deleted}` → p2's account is gone; provision `p3` with a password (test tooling) → `deleted` revokes the identity and keeps the account (`platform_link.account_kept` in the audit log); the generic admin delete for p3 with the admin API key → **409** `account_in_use` |
| `cf2-file-instance.sh` | Two `keyed` reference-client instances of client `nextcloud`, one Synaplan user linked on both (two keys). Upload `spec.pdf` with `source_id=5678` through each key → two `BFILES` rows with different `BSOURCEINSTANCE`; `overwrite=1` through key A replaces only A's row; `check-stale` through key B answers B's etag; an upload with the person's own API key (no identity) and `source_id=5678` matches neither (C19). A third, `sso` instance uploads with a 110-character id and a quoted etag; both come back unchanged |
| `cf2-apps-card.sh` | `GET /api/v1/me/platform-links` as Ada (organisation) lists the reference-client row with `mode=sso`, `status`, `last_seen`; `POST …/{id}/turn-off` → the next bearer call from the instance answers `403 platform_disconnected`; `…/resume` → 200 again. As Ada (linked) with a `keyed` link: `DELETE …/{id}` → the row stays with `status=revoked` and the key answers 401; linking again on the same `(client, instance, uid)` → the same row is `active` with a new key. For Carol's provisioned identity `DELETE` → **403** `managed_by_administrator` |
| `cf2-flag-off.sh` | `disable_flag`; every route CF2 adds (`sso/*`, `identities/adopt`, `lifecycle`, `me/platform-links/{id}/turn-off`, `…/resume`) answers **404**; `enable_flag` |

### L3

| # | Cloud | Journey | Expect |
| - | ----- | ------- | ------ |
| 1 | OpenCloud (P2) | J-CF-1 | First Summarize shows the consent card with "Signed in as ada@example.org"; **Use Synaplan** → summary; Synaplan → Apps → OpenCloud: "Connected through your organisation login · last used just now" |
| 2 | OpenCloud (P2) | J-CF-1 turn off | **Turn off for OpenCloud** shows its consequence sentence; the next Summarize in OpenCloud says "You turned Synaplan off for this app in Synaplan." with **Open Synaplan**; **Turn back on** restores |
| 3 | Nextcloud (P1, CF-S2 yes) | J-CF-1 | Log in to Nextcloud through Keycloak as `ada`; first Synaplan action → consent card → works without any Connect; admin screen shows the four modes (W8) with **Check organisation login** green |
| 4 | Nextcloud (P1) | local admin without OIDC session | Sees the Connect card, not an error |
| 5 | ownCloud.online (P3) | J-CF-2 re-walk; admin screen | Organisation login row reads "not available on ownCloud.online" |
| 6 | each cloud | J-CF-11 identity half | Disconnect in Synaplan → the cloud shows Connect / "turned off" within one action; reconnect works and Synaplan shows the same connection again (not a second one) |
| 7 | Nextcloud, ownCloud.online (OpenCloud has no disabled-user event) | Cloud admin disables `ada` (Nextcloud: `occ user:disable ada`) | Synaplan Apps card: "Paused — your Nextcloud account is disabled"; re-enable → active again |
| 8 | Synaplan (P1) | Admin → People → Platform instances | Nextcloud and the reference client are listed with mode counts and "Synaplan can reach this cloud: yes"; approving a private hostname shows the "Approval" sentence of `05` §3; removing the approval is one click (OpenCloud is listed from P2) |
| 9 | each cloud | J-CF-12 identity part: point the app at a core with `PLATFORM_LINKS.ENABLED=0` | Bind and consent changes hidden; summarize still works in shared mode; one info line in the server log, no error toast |
| 10 | Nextcloud (P1), ownCloud.online (P3) | delete a provisioned person who also uses Synaplan elsewhere (C18) | Carol's provisioned account gets a password (forgot-password mail in MailHog) and links a second cloud — in P1 a `keyed` reference-client instance, in P3 Nextcloud; the first cloud's admin deletes `carol` → the second link keeps working as Carol, the Synaplan account stays, Synaplan → Apps no longer lists the first cloud for her. In P3 walked in both directions |

---

## 4. CF3 — Files both ways

Preconditions: CF2 exit for the phase; scheduler running (sweeps; the
`CLOUD_DELIVERY_*` test values are set on `backend`, `worker` and
`scheduler`). The phase's app registered with a delivery URL the `backend`
container reaches under the dev rule of `03` §2: P1
`http://nextcloud/index.php/apps/synaplan_integration/api/v1/inbound` and the reference
client `http://cloud-fixture:8099/inbound`; P2
`http://host.docker.internal:9106/api/synaplan/inbound`; P3
`http://owncloud/index.php/apps/oco_synaplan/api/v1/inbound`. `instances/self` shows
`reachable: true`.

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
| `cf3-copy.sh` (`CLOUD=…`) | As Ada: upload `chart.png`; `POST /api/v1/files/{id}/send {destination: platform, identity_id, target: {kind: kind_folder}}` → 200 within 30 s with `reference = Synaplan/Images/chart.png` and `context = {delivery_id, state: delivered, open_url}`; the cloud helper finds the file as Ada; send again → `chart (2).png`; `db_query` shows two `BCLOUDDELIVERIES` rows `delivered`. Stop the cloud container; send → the failure code `unreachable` and the sentence "Synaplan could not reach …. Nothing was copied."; no ledger row left `queued`. Start the cloud; set the app's `dev_hold_inbound` switch (`08` §0.8); send → **202** with `context.state = pending`; with `CLOUD_DELIVERY_UNCONFIRMED_SEC=60` on `backend`, `worker` and `scheduler` the sweep sets the row to `failed` with `unconfirmed` within 90 s and the person's "Sent to" shows the sentence; clear the switch → the app's late result turns the row into `delivered` |
| `cf3-ticket.sh` | Claim a delivery id twice → second **409** `already_claimed`; claim with another instance's credential → **403** `wrong_instance`; claim after expiry → **410** `expired` (backend started with `CLOUD_DELIVERY_TTL_SEC=5`); the `download_path` answers once, a second fetch **410** |
| `cf3-inbound.sh` (`CLOUD=…`) | Send the app's inbound URL 1 000 notifications that must fail — no `sig`, signed with another key, another `instance_id`, `ts` ten minutes old, a replay of a valid one — every answer is **401** (or **429** once the per-address limit hits), and the backend log shows no claim call from that instance meanwhile (C20); then one real copy → claimed and `delivered` |
| `cf3-pickup.sh` (`CLOUD=…`) | Put `invoice-0912.pdf` and `invoice-1003.pdf` in Ada's `Invoices` (helper); set Ada's read scope in the cloud to **Only what I watch** (`mode: watched`; helper `occ` / API) — then a task with one `folder_fetch` step (`folder: Invoices`, `pattern: *.pdf`) run through the Saved Tasks API ends `failed` with the sentence "Synaplan may not read Invoices in your …. Nothing was read."; set **Also folders I allow** + allow `Invoices` → the run's message has two attachments with the right names; `BCLOUDPICKUPS` row `done`, `listed=2`, `sent=2`; run again → `sent=0` (versions reused); the same two ids and versions held from a second `keyed` reference-client instance are not reused — the listing answers them under `upload` (C19); add `big-101mb.bin` → `partial`, skipped lists it with reason `too_large`; 21st pickup in the hour → the limit sentence |
| `cf3-webdav.sh` | `folder_fetch` on a plain WebDAV connection (the Synaplan dev WebDAV fixture) lists and fetches without a pickup |
| `cf3-flag-off.sh` | `disable_flag`: `GET /api/v1/me/platform-links` answers **404** (existing behaviour), so the Copy-to-cloud menu lists no linked clouds; `folder_fetch` is absent from the planner's capability list; claim / pickup / read-scope routes **404**; `enable_flag`. **Capability block (C21):** a reference-client instance with `app_capabilities=[]` is not listed by the menu's data and is no `folder_fetch` channel; with `[deliveries]` it is listed for copies only |

### L3

| # | Cloud | Journey | Expect |
| - | ----- | ------- | ------ |
| 1 | each cloud | J-CF-3 | Library → Generated → chart → **Copy to cloud** → "Nextcloud · localhost:8081 → Synaplan/Images"; toast "Copied to Nextcloud › Synaplan/Images/chart.png" with **Open in Nextcloud** that opens the file; the cloud's bell shows the notification; **Sent to** on the file lists it |
| 2 | each cloud | J-CF-3 again | `chart (2).png`; **Sent to** lists both |
| 3 | each cloud | J-CF-3 unreachable | Cloud stopped: "Synaplan could not reach …. Nothing was copied." + the From-Synaplan recovery sentence; no "running" state anywhere |
| 4 | each cloud | J-CF-4 | In `Projects/Q3`: **New → From Synaplan…** → Generated first → pick two → **Add 2 files** → both appear; empty picker shows "Files Synaplan makes for you appear here." + **Open Synaplan** |
| 5 | Nextcloud, ownCloud | personal setting | "Save files from Synaplan to" with the native picker → the next copy lands there, under the kind sub-folder |
| 6 | each cloud | app chat Save to Files | Document, audio and calendar kinds save (Nextcloud, ownCloud); binary image saves on OpenCloud |
| 7 | each cloud | J-CF-14 | Chat: "Take the PDFs in my Nextcloud folder Invoices and make one Excel table of vendor, date and total." → card "Read 2 files from Nextcloud › Invoices" with chips → the table file → **Copy to cloud** works on it |
| 8 | each cloud | J-CF-14 refused | Ask for `HR/Contracts` → "Synaplan may not read HR/Contracts in your Nextcloud. Nothing was read." + **Open in Nextcloud**; there, right-click → **Let Synaplan read this folder**; ask again → works; the Apps card reads "Synaplan may read: the folders you watch, HR/Contracts" |
| 9 | each cloud | read setting (W11) | Personal settings show the three choices; **All my files** shows the warning sentence; the admin cap hides choices above it |
| 10 | Synaplan | task run card (W9) | A task with `folder_fetch` shows "Reading files from Nextcloud… n of m" while running and the final sentence; the run names every file read and every file skipped |
| 11 | Nextcloud, OpenCloud | cross-cloud | Copy a file that was picked up from OpenCloud to Nextcloud; **Sent to** names Nextcloud; the OpenCloud original is untouched |
| 12 | each cloud | stability | Restart the cloud container during a copy of a 50 MB file → the ledger row ends `delivered` or `failed` within 10 min, never `queued`; restart `backend` during a pickup → the run ends with a sentence (partial or failed) |

---

## 5. CF4 — Searchable state

Preconditions: CF3 exit for the phase; `CLOUD_FILES_ENABLED=1`; source
events flowing from the phase's app (P1 Nextcloud node events and the
reference client; P2 the OpenCloud event bus or reconcile-only per CF-S3; P3
ownCloud hooks).

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
| `cf4-status.sh` (`CLOUD=…`) | Upload `spec.pdf` from the cloud as searchable (source id `s1`, etag `e1`) → `POST /api/v1/cloud/status {items: [{id: s1, version: e1}, {id: s2, version: e9}]}` → `s1: searchable`, `s2: none`; change the file in the cloud → the app's source event is answered with `reupload` naming Ada and `s1`; within 90 s `updating` then `searchable` (poll); the same with "Keep it up to date" off → `changed` and no `reupload` entry; trash it → the file disappears from `GET /api/v1/files` and from a RAG query, `BSOURCEDELETEDAT` set; restore → back without a new `BFILES` row; after a simulated 7 days (`app:cloud-files:reap --now=<date>`) the row is gone. With `PROFILE=sso`: ids of 110 characters and quoted etags come back unchanged |
| `cf4-status-perf.sh` | `many/` (5 000 files) in Ada's folder; `cloud/status` for a page of 500 ids: p95 < 1.5 s over 10 calls; second call within 60 s served from cache (`X-Synaplan-Cache: hit`) |
| `cf4-flag-off.sh` | `CLOUD_FILES_ENABLED=0` → `cloud/status` **404**; `GET /api/v1/config/runtime` has no `cloud_files` block; `instances/self.capabilities` lacks `cloud_status` |

### L3

| # | Cloud | Journey | Expect |
| - | ----- | ------- | ------ |
| 1 | each cloud | J-CF-5 | Right-click `spec.pdf` → **Make searchable by AI** ("Keep it up to date" on) → bird with clock → bird (Nextcloud, ownCloud in the row; OpenCloud in the sidebar unless CF-S1 found a row point); tooltip "Searchable by AI — in “Nextcloud · Specs”" |
| 2 | each cloud | edit | Edit the file in the cloud → bird with arrows → bird within two minutes; nothing to click |
| 3 | each cloud | sidebar | The "Synaplan" tab answers the five questions: who made it searchable, who else can ask about it, what it touches (knowledge folder), how to stop (**Remove from Synaplan** button), where it came from |
| 4 | each cloud | delete and restore | Delete → gone from Synaplan answers and Library at once; restore from trash → bird back, no new upload in the Library history |
| 5 | each cloud | Bob | Bob opens the shared `Specs` → no bird on files he did not add (v1 shows *my* state) |
| 6 | each cloud | failure | Stop Synaplan → no glyphs, no toast, one quiet line in the sidebar ("Couldn't check Synaplan right now"); start it → glyphs return on the next navigation |
| 7 | each cloud | flag off / older core | `CLOUD_FILES_ENABLED=0` → no glyph, no sidebar tab, no console error (J-CF-12) |
| 8 | each cloud | themes | Glyph contrast ≥ 3:1 in the host's light and dark themes (measured); the white-label override in runtime config replaces both glyphs |

---

## 6. CF5 — Watched folders and automations

Preconditions: CF4 exit for the phase; the phase's cloud runs its background
jobs (the `cron` container for Nextcloud and ownCloud.online, the Go service
for OpenCloud); scheduler running; `--compute` for J-CF-13; Ada's cloud key
carries `cloud:watch` after the permission card.

### L1

Watch API tests; ledger unique key (C6); loop guard three layers (C7);
file targets (only new versions of that file; deletion and restore); watch
index paging, `since` with tombstones, `304`, `410`; caps (20 per person,
1 000 per instance); quiet period and windowing (`BWINDOWSEC`, `BMAXFILES`)
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
| `cf5-watch.sh` (`CLOUD=…`) | Create a **Summarize and mail me** watch on `Reports` through `POST /api/v1/cloud/watches` (Ada's key with `cloud:watch`); the row is `active`; upload `report.pdf` version `v1` to `…/watches/{id}/files` → one `BCLOUDWATCHITEMS` row → run → MailHog has one mail "Summary: report.pdf" with a non-empty summary, **Open in …** and **See this run in Synaplan** links (TestProvider; the sentence count is L3 row 4); upload `v1` again → "already handled", no second run or mail (C6); `PATCH {state: paused}` → upload `v2` → no run; resume → the resume answer lists 1 waiting file → run; `DELETE` → the watch is `stopped` (row and item ledger kept), the preset task is disabled with "Its watch was stopped", the watch index returns a tombstone. **File block:** a Translate watch with `target_kind: file` on `Projects/Q3/price-list.docx` — a new version → one run and `price-list.en.docx`; a second new version → the output is replaced, not duplicated (copy-ledger remote id); another file in `Projects/Q3` → no run; trash the file → the watch reads `attention: target_gone`; restore → `active` |
| `cf5-index.sh` | Seed 1 000 watches over 50 test identities (20 each) on a fake instance (dev command); `GET platform-links/watches?limit=500` → 2 pages, each ≤ 500, no watch twice; change one watch → `since=<cursor>` returns exactly that one; stop one → a tombstone; nothing changed → **304**; an expired cursor → **410**; the 21st watch of one person and the 1 001st of the instance → **409** with the sentences of `04` §B.7 |
| `cf5-quiet.sh` | Upload `v1`, `v2`, `v3` of the same file within 40 s → one run (for `v3`) after the 60 s quiet period; the server clock is read inside the container |
| `cf5-loop.sh` | With the app's `dev_allow_self_write` switch (`08` §0.8): a **Translate** watch on `Incoming-DE` writing into `Incoming-DE` itself: one run produces `contract.en.docx`; the app's own-write guard and the copy-ledger lookup leave it unprocessed; no second run within 5 min (C7) |
| `cf5-reconcile.sh` | Set the app's `dev_mute_events` switch; upload two files; `POST …/watches/{id}/reconcile` with the folder listing → the answer names the two ids to process → runs; a listing without a known id → the answer lists it under `forget` and its item ends `skipped` with reason `gone` |
| `cf5-limits.sh` | 21 uploads to a Summarize watch in one hour → 20 mails, the row's `counts.waiting = 1` and the sentence "1 file waiting until <next hour>"; a 101 MB upload → `skipped: too_large` with the sentence |
| `cf5-fileset.sh` | Watch with `file_set: together`, `window_sec: 60`, task "merge CSVs" (no file work): upload the three CSVs within 30 s → exactly one run after the window, three attachments, payload `set.count = 3`; `file_set: whole_folder`: `POST …/snapshots` with the listing → answer lists only versions Synaplan lacks → `…/close` → one run with the folder's files; a snapshot of 312 files → refused with the "at most 50" sentence; a snapshot listing whose ids and versions match copies held from another instance gets them back as versions to upload (C19) |
| `cf5-trial.sh` | `POST /api/v1/cloud/trials` with `invoice-0912.pdf` + instruction → chat URL; the chat's first message has the attachment; `POST /api/v1/cloud/watches/from-message/{id}` → a watch with a task whose steps equal the trial turn's pinned steps; `file_set` and `allow_unattended` as sent; a pinned file-work script containing `invoice-0912.pdf` → **422** with the sentence of `07` §10 ("Pinned script names the test file") |
| `cf5-lifecycle.sh` | Disconnect Ada's identity → both watches `paused` with reason `identity_off`; upload → no run; reconnect → `GET …/watches` returns the resume question data (`paused_count = 2`); resume → files that arrived meanwhile are handled |
| `cf5-flag-off.sh` | `CLOUD_FILES_ENABLED=0` → watch routes **404**; existing watches keep their rows; `instances/self.capabilities` lacks `watches`; the app hides the Watch menu (J-CF-12) |

### L3

| # | Cloud | Journey | Expect |
| - | ----- | ------- | ------ |
| 1 | each cloud | permission card | First **Watch with Synaplan…** shows "Let Synaplan watch folders for you?" once; after confirming, the key carries `cloud:watch` (API keys list) |
| 2 | each cloud | J-CF-6 | Watch `Specs` → **Keep them searchable** → "Also the 7 files already in this folder" (on) → **Start watching**; eyes on `Specs`; birds appear; Tasks → **Watched folders** row "Specs · Nextcloud · 7 searchable"; Library knowledge folder "Nextcloud · Specs" with "Kept in sync with Nextcloud › Specs"; a chat answer cites a Specs file; new file → bird within two minutes; **Stop syncing** → eyes gone within a minute, files stay searchable |
| 3 | each cloud | J-CF-7 | Translate watch → drop `contract.docx` → `Incoming-EN/contract.en.docx` after the quiet period; row "Last: … · 1 translated" with **Open in …**; same version again → nothing; Pause → drop → nothing; Resume → "Also handle the 1 file that arrived while paused?" → translated; `contract-scan.pdf` → "contract-scan.pdf has no readable text. Nothing was translated." + a cloud notification |
| 4 | each cloud | J-CF-8 | Summarize watch → `report.pdf` → mail with 4–5 sentences, **Open in …**, **See this run in Synaplan**, readable at 360 px in MailHog's preview; 30 files → 20 mails this hour, the row says "10 files waiting until <hour>" |
| 5 | each cloud | J-CF-9 | **Run my instruction** → **Try it on a file** → trial chat with the invoice attached and a table; refine; **Do this for every new file in “Invoices”** → **Mail me**; new invoice → mail with *that* invoice's table; switch to **Save next to the file** → `invoice-1003.answer.md` appears; **Edit in Synaplan** opens the Steps editor; "Ask me before this step" → the next file waits in Approvals |
| 6 | each cloud | J-CF-10 | Bob opens the shared `Incoming-DE`: eyes; sidebar "Ada's Synaplan watches this folder: …. Only Ada can change this."; no Pause / Stop for Bob; Ada stops → Bob's eyes gone within a minute; admin turns "Allow watching shared folders" off → Ada's dialog offers no shared folder; the existing watch reads "Stopped — your administrator turned off watching shared folders" |
| 7 | each cloud | J-CF-11 full | Disconnect → Connect shown in the cloud; both watches "Paused — … is disconnected from your Synaplan account" on both sides; drop a file → not processed; reconnect → "Resume 2 watched folders?" → **Resume** → handled |
| 8 | each cloud | J-CF-12 | App against a core with `CLOUD_FILES_ENABLED=0`: no Watch menu, no eyes, no console error |
| 9 | each cloud (`--compute`) | J-CF-13 | **New files together** + instruction with file work → **Try it on files** with three CSVs → trial shows the file-work card and `revenue.xlsx` with a chart; **Do this for every new file** → "This task does file work. Let it run without asking each time?" → **Yes**; three CSVs dropped → one run after the window with three attachments → `revenue.xlsx` next to them; the run names three inputs and one output; with **Ask me each time** the run waits in Approvals: "Run file work on 3 files from Exports?"; on a stack without Compute the dialog never mentions file work |
| 10 | Nextcloud, ownCloud.online | background-jobs warning | Set `occ background:ajax` → the admin screen shows the W8 warning ("Watched folders need background jobs that run on a schedule"); the watch dialog says watches need background jobs; back to `cron` clears it |
| 11 | Synaplan | Watched folders tab (W7) | Empty state sentence + action when Ada has no watch; rows with cloud, folder, preset, last run, counts; Pause / Resume / Stop from the row with the kind-specific consequence sentence; run detail shows the files with **Open in …** |
| 12 | each cloud | stability | Kill the `cron` container during a run → the item ends `failed` with a sentence within 30 min (sweep), never "running"; stop Synaplan for 5 min while files arrive → the app's job list retries; after start, every file has one run |
| 13 | each cloud | cost (C14) | Each handled file is one task run in Ada's usage; at the plan limit the row says "Your Synaplan limit for today is reached — n files wait until tomorrow." |
| 14 | each cloud | J-CF-15 | Watch the single file `Projects/Q3/price-list.docx` → **Translate it**; the dialog asks "What should happen when this file changes?" and has no file-set block; the row shows the bird with eyes; a new version replaces `price-list.en.docx`; another file in `Projects/Q3` starts nothing; Tasks → Watched folders lists it as a file; Pause / Resume from the sidebar; delete → "Stopped — the file was deleted"; restore → watching; **Stop watching** → eyes gone, the translation stays |

After the P1 exit: `cloud_files` on for new installs and the activation
migration (row 46) — verified by a fresh install (`docker compose down -v`
in a scratch checkout) showing the flag on.

---

## 7. Regression set — re-run after every sprint

| Suite | Command | Phases |
| ----- | ------- | ------ |
| Synaplan gates | `make ci-local && make test-e2e` (+ `make -C frontend test-e2e-layout` when nav or layout changed; `npm run test:e2e:oidc` when auth changed) | all |
| Platform links | `_devextras/testing/platform-links/fake-instance.sh` and `--flag-off` | all |
| Reference client | every `cf*.sh` of the sprints finished so far with `CLOUD=fixture PROFILE=keyed` and `PROFILE=sso` | all |
| Cloud apps L2 | every `cf*.sh` of the sprints finished so far with the phase's `CLOUD=`; in P2 also with `CLOUD=nextcloud`, in P3 also with `CLOUD=nextcloud` and `CLOUD=opencloud` (earlier phases must stay green) | all |
| Compatibility check | after each Synaplan release in P1: OpenCloud v1.0.0 (organisation login: summarize, knowledge) and ownCloud.online 1.0.0 on the host install (provision: summarize, translate, knowledge status) behave as before or better — `09` §1 rows 8–9 (C1) | P1 |
| Nextcloud app | `make lint && make test`; Playwright against `make dev-up` (from CF1.3) on the matrix images `nextcloud:30-apache` (floor) and `nextcloud:34-apache` (top) (`NC_IMAGE`) | all |
| OpenCloud app | `make frontend-lint frontend-typecheck frontend-test-unit backend-test backend-lint && make frontend-test-e2e-dev` | P2, P3 |
| ownCloud.online app | `make lint && make check-types && make test` (from CF1.2); L3 checklist of the last sprint by hand | P3 |
| Contract | the core contract test, and the checksum test in every app repo that vendored the fixtures (C22) | after CF5.10 |
| Mobile class | `node scripts/mobile-impact.mjs --base main --head HEAD` in `synaplan` (then delete `mobile-release-manifest.json`) | all |
| Environment | `env-check.sh` with the phase's profile (§0.2) | all |

---

## 8. Phase exits

### 8.1 P1 exit — core and Nextcloud

1. §1–§6 green for Nextcloud: every L2 script with `CLOUD=nextcloud`, every
   L3 row that names Nextcloud, P1 or "each cloud" walked on Nextcloud
   (J-CF-1 only if CF-S2 said yes), STATUS entries in the §0.5 format.
2. Every L2 script green with the reference client in both profiles — the
   proof for the organisation-login paths, long ids and quoted etags that
   only OpenCloud and ownCloud.online will use.
3. The compatibility check of §7 green against the last P1 Synaplan release.
4. CF5.10 merged: contract v1 fixtures complete, the contract test green,
   `instances/self.contract = 1`, the Nextcloud checksum test green, a STATUS
   decision naming the frozen commit.
5. The five spikes answered in STATUS; every core consequence merged.

After the P1 exit (not part of it): the Synaplan release and the Nextcloud
app release are published, the Nextcloud docs updated, and row 46
applied to the flags (`00` §8).

### 8.2 P2 exit — OpenCloud

1. The OpenCloud app vendors contract v1 and its checksum test is green.
2. §1–§6 green with `CLOUD=opencloud` (rows marked P2 or "each cloud");
   J-CF-1 walked on OpenCloud.
3. Nextcloud and reference-client regression green on the same Synaplan
   release; any core change of P2 is additive and has a STATUS decision (C22).
4. The OpenCloud app release (container image) and its docs page published.

### 8.3 P3 exit — ownCloud.online

1. E-5 green; the app vendors contract v1 and its checksum test is green.
2. §1–§6 green with `CLOUD=owncloud` (rows marked P3 or "each cloud"), walked
   by hand where Playwright does not run.
3. Nextcloud, OpenCloud and reference-client regression green.
4. The ownCloud.online package and its docs page published; `00` §5 has no
   open cell; `00` §10 holds for all three clouds.
