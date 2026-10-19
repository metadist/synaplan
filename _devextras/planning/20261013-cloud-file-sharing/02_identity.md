# Goal 2 — one login story, one way of handling people

Plan of record: [`00_master_plan.md`](./00_master_plan.md) (decisions 3–10,
38–40, 43).

## 1. Target

A person uses the same Synaplan account in every cloud app, and both sides
always agree on who that is. There are four ways in, named the same in all
three apps. Whatever the way in, Synaplan ends up with **one identity row per
person per cloud**, and that row drives the Apps card, copies, watches,
deletion and the admin view. Credentials differ by mode; the row does not.

Nextcloud already proves the hard part (link handshake, provisioning, consent,
deletion). This plan generalizes it, adds the organisation login where the
identity provider is shared, and closes what is missing on ownCloud.online and
OpenCloud.

---

## 2. Four modes

| Mode (admin label) | Pick it when | What the person does | Credential used | Nextcloud | ownCloud.online | OpenCloud |
| ------------------ | ------------ | -------------------- | --------------- | --------- | --------------- | --------- |
| **Organisation login** | Cloud and Synaplan trust the same identity provider (Keycloak, Nubus / openDesk, …) | Nothing to connect; one consent card on first use | The person's login token, exchanged for a Synaplan token | **add** (CF2.6, after CF-S2) | v2, hoster request | ✓ today (mode A) + bind (CF2.7) |
| **Connect your Synaplan account** | People already have Synaplan accounts, identity providers differ | Clicks **Connect Synaplan** once, signs in, confirms one card | Per-user scoped key from the handshake | ✓ 1.6.0 | **add** (CF1.1) | **add** (CF2.7, key store per CF-S3) |
| **Accounts created by the administrator** | A hoster gives every cloud user a Synaplan account | Consents once ("Activate AI") | Per-user scoped key minted with the admin key | ✓ | ✓ | cut line |
| **One shared account** | A demo or one small team | Nothing | One instance-wide key | ✓ | ✓ | ✓ (mode B) |

Rules for all modes:

- Admin screens show the four modes in this order, each with one sentence of
  what it means for the people in this cloud. "One shared account" carries the
  warning "Everyone here acts as one Synaplan account and sees its files. Not
  for teams with private documents."
- Nextcloud keeps its stored values (`shared`, `provision`, `link`) and adds
  `sso`. ownCloud.online replaces its boolean with the same enum (existing
  `per_user_accounts = true` reads as `provision`).
- Watches, the personal "Save files from Synaplan to" folder and the bird
  for *my* files need a per-person mode. In One-shared-account mode those
  surfaces are absent and the admin screen says why (U11).

---

## 3. The identity row

`BEXTERNALIDENTITIES (BSOURCE = client, BINSTANCEID = instance, BEXTERNALID =
platform user id) → BUSERID, BAPIKEYID` exists. Linking writes it in exactly
that shape (Synaplan's platform instance id, the bare platform user id, the
minted key). Provisioning does not (D15): it writes `BINSTANCEID = ''` and
`BEXTERNALID = '<cloud instanceid>:<uid>'`, and the key it mints next is not
bound to the row. This plan makes the canonical shape the rule for every
per-person mode; §3.1 brings provisioned rows into it before anything looks
them up by instance.

| Mode | Who writes the row | When |
| ---- | ------------------ | ---- |
| Organisation login | **new** `POST /api/v1/platform-links/sso/bind` | When the person clicks **Use Synaplan** on the consent card |
| Connect your account | `PlatformLinkExchangeService` (exists) | Code exchange |
| Accounts by administrator | `AdminUserProvisioningService` — canonical when the call carries the instance credential (§3.1) | Provisioning; existing rows through adoption (§3.1) |
| One shared account | — (no per-person row; the instance row names the shared key's owner) | — |

The row gains two columns (schema ask, §9): `BMODE` (`sso` / `link` /
`provision`) and `BSTATUS` (`active` / `paused` / `revoked`), plus
`BREVOKEDAT`.

### 3.1 Provisioned rows into the canonical shape (CF2.2)

1. **The writer.** `POST /api/v1/admin/users` and
   `POST /api/v1/admin/users/{id}/api-keys` accept the instance credential
   beside the admin key. With it, Synaplan writes `(client, platform instance,
   platform uid)` — client and instance from the credential, the bare uid
   from the body — sets `BMODE = provision` and binds the minted key to the
   row. Without it (older app releases, clouds that never registered) both
   calls behave as today. The admin screen therefore registers the cloud
   (decision 6) before it provisions anyone.
2. **Adoption of existing rows.** After the upgrade each app runs a one-time
   background job: for every local user with a stored per-user key it calls
   `POST /api/v1/platform-links/identities/adopt` with the instance credential
   **and that user's key**. Synaplan rewrites the key owner's row
   `(client, '', '<server id>:<uid>')` to the canonical tuple and binds that
   key. The server id must be the one the instance reported through
   `PATCH instances/self` (`server_id`, the cloud's own `instanceid`).
   Checked twice, like bind: an instance cannot adopt a person without that
   person's key, and a key cannot pick an instance. A repeated call answers
   200 with the canonical row. If the canonical row already exists — the
   person linked the same cloud before — Synaplan binds the key to that row,
   deletes the legacy row and answers 200; it never writes a second row for
   the same `(client, instance, uid)` (unique key `uniq_extid_source`).
3. **What stays.** A row no app adopts keeps today's behaviour: provisioning
   still finds the person through the user details, nothing shows it as a
   connection, and lifecycle calls cannot reach it — its cloud's old release
   uses the generic admin delete, which follows the rule of §7. A migration
   sets `BMODE` on existing rows: `link` where an instance and a key are set,
   `provision` for the legacy shape, `NULL` for `oidc:` rows.
4. **No second row.** Provisioning without the instance credential finds an
   adopted person through the user details (`external_source`,
   `external_id`) and never writes a second row for them.

What the row powers:

| Surface | How |
| ------- | --- |
| Synaplan Apps card per cloud | Status sentence per mode, last seen, counts (watched folders, searchable files) |
| Copy to cloud | The copy names the identity; the app writes into that platform user's storage |
| Watches | A watch belongs to an identity; revoking it pauses the watch |
| Lifecycle | The app reports a deleted / disabled platform user by id |
| Admin → People → user detail | One badge per identity (existing `ExternalIdentity::badge()`), now with mode |

**Bind is checked twice.** The call carries the person's live bearer token
*and* the instance credential, and the token's `azp` must equal the OIDC
client registered for that instance. A different app cannot bind people to an
instance, and an instance cannot bind a user without that user's live token.

**Which client is `azp`.** The instance registers as `oidc_client_id` the
client id that appears as `azp` in the tokens the app sends to Synaplan — not
the client people log in with. For OpenCloud that is its token-exchange
client (`synaplan-opencloud` in dev), because the exchanged token is issued
to it; for Nextcloud it is whatever CF-S2 records. The exchanged token's
audience is Synaplan's own OIDC client (`OIDC_CLIENT_ID`, `synaplan-app` in
dev). `cf2-identity.sh` decodes a real exchanged token and checks `azp`
before it asserts on bind.

**Attribution.** `OidcBearerAuthenticator` already validates the token. When
`azp` matches a registered instance, it now touches the identity row's
`BLASTSEEN` and refuses with `403 platform_disconnected` when the row is
paused or revoked. Tokens from clients that are not registered behave exactly
as today (C1).

**Bearer tokens on chat (D18).** Today the `openai_compat` firewall (`^/v1`)
accepts only API keys. CF2.2 adds `OidcBearerAuthenticator` to that firewall
for exactly `POST /v1/chat/completions` and `GET /v1/models`, with the same
attribution; every other `/v1/*` path still refuses bearer tokens.

---

## 4. Credentials

| Work | Organisation login | Connect / Accounts by admin | One shared account |
| ---- | ------------------ | --------------------------- | ------------------ |
| Interactive (the person clicks) | Exchanged token (`Authorization: Bearer`) | Per-user scoped key (`X-API-Key`) | Shared key |
| Unattended (watch uploads, reconciliation) | **Background key** minted once through the bearer path | The same per-user key | — (no watches) |
| Instance operations | Instance credential | Instance credential | Instance credential |

**Instance operations** use the existing platform-links instance credential
(`X-Instance-Id` + `X-Instance-Secret`, today used by `instances/self` and the
code exchange). They never read file contents, chats or memories (C5):

1. claim a copy and report its result (`03` §2),
2. report source changes and deletions for files from this cloud (`04` §A.5),
3. read the descriptions of active watches on this cloud — in pages, then
   only what changed — to show the eyes to everyone with access and to match
   file events (`04` §B.8),
4. bind an organisation-login user (with the user's token),
5. report a deleted / disabled / re-enabled platform user,
6. fulfil a pickup Synaplan asked for — claim it, send the listing, upload
   the files into that ticket, report the result (`07` §4). The ticket names
   the person and the checked selector; the instance never picks whose
   Synaplan receives files,
7. report what a person lets Synaplan read (`07` §5),
8. adopt a provisioned person's identity row — only together with that
   person's key (§3.1).

This stays inside Wave 6 §0 #5 — "API key for editors and bots; platform-links
handshake or OIDC for hosted apps … do not invent a fourth scheme". No
"instance acts for any user" credential is introduced.

### 4.1 Scopes

| Change | Why |
| ------ | --- |
| `chat` gains exact branches `POST /api/v1/summary/generate`, `POST /v1/chat/completions`, `GET /v1/models`, `POST /api/v1/media/generate` | D1 — the paths the apps already call. Exact method + path branches before the `/v1/` prefix, same pattern as the app-accounts plan's `app:ai` (C3). Generated media is downloaded through `/api/v1/files/uploads/…` (`StaticUploadController`), which `files` already covers |
| `GET /api/v1/config/memory-service/check` is allowed for `chat` **or** `memories` | Provisioned keys carry `chat files rag` only (`ApiKeyScope::provisionedPlatformScopes()`), and the apps call this check before every chat |
| `files` covers `POST /api/v1/cloud/status` and `GET /api/v1/cloud/files/*` | Reading the state of my own files is file access |
| **new** `cloud:watch` covers `/api/v1/cloud/watches` and `/api/v1/cloud/watches/*` except `from-message`, and `POST /api/v1/cloud/trials` | Watching runs tasks while the person is away — a new permission, granted on purpose. A trial uploads a file to try a watch on |
| `POST /api/v1/cloud/watches/from-message/*` is **session only**: API keys get `403` | It pins a turn of a chat in Synaplan web; no app calls it |
| `POST /api/v1/platform-links/identities/adopt` accepts a key with any of `chat`, `files`, `rag` (plus the instance credential) | The person's own provisioned key proves the person |
| Background key = `files rag cloud:watch` | Enough to upload watched files, re-upload files kept up to date and run the person's watch tasks, nothing else |

A contract fixture lists every Synaplan path each app calls (from `01` §2) and
asserts allow for its scope set and deny for the neighbours.

### 4.2 Adding the watch permission

Existing linked and provisioned keys do not carry `cloud:watch`, and scopes
are never widened silently. The first **Watch with Synaplan…** in a cloud
shows one extra card:

> **Let Synaplan watch folders for you?** It reads new files in the folders
> you pick and runs what you chose, also while you are away. You can stop it
> any time here or in Synaplan.

- Connect your account: the card re-runs the existing handshake with the added
  scope (one confirm in Synaplan); the old key is revoked after the new one
  arrives.
- Accounts by administrator: the app re-mints with the admin key; the card is
  still shown (the person decides, the hoster pays).
- Organisation login: the card mints the background key through the bearer
  path.

---

## 5. Flows per mode

### 5.1 Organisation login

```text
 Person (cloud UI)        Cloud app server                       Identity provider          Synaplan
 ─────────────────        ────────────────                       ─────────────────          ────────
 first Synaplan action ─► no identity yet? → consent card
 clicks "Use Synaplan" ─► token for Synaplan's client ─────────► token exchange ──► token
                          POST /platform-links/sso/bind  (Bearer + instance credential + platform uid) ─────► check azp,
                                                                                                    write row (mode sso)
 every later action ───► Bearer token (cached until expiry − 30 s) ───────────────────────────────► runs as the person
```

- **OpenCloud** does the exchange today (`backend/internal/tokenexchange/`).
  New: the consent card, the bind call, `GET /api/v1/platform-links/sso/status`
  to know whether to show the card, and registration as an instance (OpenCloud
  instances do not register today).
- **Nextcloud** obtains the token from the `user_oidc` app. Assumed mechanism,
  confirmed in spike CF-S2 before CF2.6 starts: `user_oidc` stores the login
  token when configured to, and lets other apps request it or an exchanged
  token for a target audience through events (`integration_openproject` uses
  this path). People who signed in without OIDC (a local admin) see the
  Connect card when Connect is also enabled, otherwise "Sign in with your
  organisation account to use Synaplan."
- **Admin check** in each app: "Check organisation login" exchanges a token for
  the admin's own session and calls `GET /api/v1/auth/me`. Result is a sentence:
  "Works — Synaplan sees you as ada@example.org", or "Your identity provider
  did not let Nextcloud sign you in to Synaplan. Ask its administrator to
  allow it." The **Advanced** section below names the technical setting
  (OpenID Connect token exchange from the Nextcloud client to Synaplan's
  client).
- **Open in Synaplan** links (runs, files, watched folders) land on Synaplan's
  login page; with OIDC auto-redirect on (`LoginView.vue`, `auto_redirect`)
  the person arrives without a click.

### 5.2 Connect your Synaplan account

- **Nextcloud:** shipped (J-NC-1…3); walked in CF0.7.
- **ownCloud.online:** port of Nextcloud's `PlatformLinkService`,
  `LinkController`, personal settings, consent gate and the admin "Register
  this instance" button (CF1.1). Client `owncloud`, same handshake.
- **OpenCloud:** the handshake needs a place for per-user keys. The Go service
  has no database. Spike CF-S3 picks between OpenCloud's persistent store
  (the store OpenCloud's own services use) with encryption by a key from the
  environment, and a small encrypted file store on a volume. Personal settings
  live in an `accountExtension` (CF-S1). Cut line: may move to CF6 if CF-S3
  shows a large cost — OpenCloud's main deployments (openDesk) share an
  identity provider.

### 5.3 Accounts created by the administrator

Unchanged in Nextcloud and ownCloud.online. Disconnect in Synaplan is **not**
offered for these accounts — the app would re-mint with the admin key and
undo it. The Apps card says "Managed by files.example.org — ask your
administrator to remove it."

### 5.4 One shared account

Unchanged. The Synaplan Apps card is shown to the owner of the shared key:
"files.example.org uses your account for everyone there." **Disconnect**
revokes that key; consequence: "Everyone at files.example.org loses Synaplan
until an administrator sets a new key."

---

## 6. Synaplan surfaces

### 6.1 Apps cards (Nextcloud, ownCloud.online, OpenCloud)

`status.ts` reads identity rows (`GET /api/v1/me/platform-links`, extended
with mode, status, last seen and counts) instead of platform links only
(CF2.5, P1). The cards render in
`frontend/src/components/config/LinkedPlatformsConfiguration.vue`, which the
Outlook connection shares — the Outlook flow must stay unchanged (C2).
`frontend/src/apps/catalog.ts` gains the `opencloud` entry in P2 (CF2.11):
before CF2.7 binds them, people who use OpenCloud through their organisation
login have no identity row, and a card saying "not connected" would be wrong.

| Mode | Status sentence | Primary action | Consequence of the action |
| ---- | --------------- | -------------- | ------------------------- |
| Organisation login | "Connected through your organisation login · last used today 09:12" | **Turn off for OpenCloud** | "OpenCloud stops using your Synaplan account. Your 2 watched folders pause. Files already in Synaplan stay." |
| Connect | "Connected as jdoe at files.example.org since 3 Oct" | **Disconnect** | Same sentence as above, kind-specific per app |
| Accounts by admin | "Managed by files.example.org" | — | — |
| Shared | "files.example.org uses your account for everyone there" | **Disconnect** | §5.4 |
| Turned off (sso) | "Turned off on 13 Oct" | **Turn back on** | "OpenCloud can use your Synaplan account again. Paused folders stay paused until you resume them." |
| Paused (cloud account disabled) | "Paused — your Nextcloud account is disabled" | — | Resumes when the cloud's administrator enables the account again |

Every card also lists what the app can do in one line, built from the
features the app reports (`app_capabilities`, row 43) — "Summarize, translate,
make files searchable, copy results into your OpenCloud, watch folders" —
what Synaplan may read there ("the folders you watch, Invoices/2026" — set in
the cloud, `07` §5) and links to **Watched folders** filtered to that cloud. Empty state (no
connection): one sentence that names where to start ("Start in Nextcloud:
Settings → Synaplan") + **How to connect** (U5).

### 6.2 Admin

- Admin → People → Platform instances (`PlatformInstancesTab.vue`) lists
  OpenCloud instances too, with mode, app version, "Synaplan can reach this
  cloud: yes / no" (checked when the delivery URL is saved) and the number of
  people connected.
- Approving a delivery address on a private network says so:
  "files.example.lan is on a private network (192.168.10.20). Synaplan will
  call it to deliver copies and requests. Approve only clouds you run." The
  approval stores the **hostname** (`BAPPROVEDHOSTS`), not the address, so a
  container restart that changes the address does not break it; Synaplan
  still refuses the hostname if it resolves to loopback or link-local
  addresses. Removing the approval is one click in the same row.
- User detail shows one badge per identity with its mode.

### 6.3 Errors the apps show

| Code from Synaplan | Sentence in the cloud | Recovery |
| ------------------ | --------------------- | -------- |
| `platform_disconnected` | "You turned Synaplan off for this app in Synaplan." | **Open Synaplan** (Apps card) |
| `instance_revoked` | "The Synaplan administrator disconnected this Nextcloud." | Admin: "Register again" |
| `insufficient_scope` on a watch call | "Synaplan needs your permission to watch folders." | The card from §4.2 |
| 401 on a per-user key | "Your Synaplan connection ended. Connect again." | Connect card (existing) |
| Token exchange refused | "Your organisation login does not reach Synaplan right now." | Retry; admin check |

---

## 7. Lifecycle

| Event | Synaplan | The app |
| ----- | -------- | ------- |
| Platform user deleted | `lifecycle(deleted)`: this identity revoked, the keys bound to it revoked, its watches stopped. The Synaplan account is deleted only while this cloud alone manages it (rule below); otherwise it stays and the audit row says why | Nextcloud `BeforeUserDeletedEvent`, ownCloud `preDelete` (both exist; today they call the generic admin delete — D16), OpenCloud user-deleted event (CF-S3) |
| Platform user disabled | `lifecycle(disabled)`: identity `paused`; watches paused with reason `platform_user_disabled` ("Paused — your Nextcloud account is disabled", `04` §B.2) | Nextcloud user-changed event (`enabled`), ownCloud equivalent (CF-S4) |
| Re-enabled | `lifecycle(enabled)`: watches paused by that reason resume; the owner is told | Same events |
| Disconnect in Synaplan | Identity row kept with `BSTATUS = revoked` (today the row is deleted — D19), key revoked, watches paused (`identity_off`); `identity_revoked` notification from CF3.1 on | Clears the local key on the notification, or at the latest on the next call (401); shows Connect / "turned off" |
| Disconnect in the cloud | Key revoked (exists); identity row `revoked`; watches paused | Personal settings (exists in Nextcloud) |
| Connecting again | The link exchange finds the row by `(client, instance, uid)` and sets it `active` with the new key; paused watches stay paused until the person answers "Resume 2 watched folders?" | Connect card (exists) |
| Synaplan account deleted | Rows, keys and watches go with the account | Next call 401 → Connect card |
| Instance revoked | Every identity of the instance revoked; watches paused | Admin screen explains (§6.3) |
| Organisation login session ends | — | Interactive actions ask to sign in again; unattended work continues on the background key |
| Background key revoked | Watches of that person pause: "Allow watching again" | §4.2 card |

**Deleting a person in one cloud** (decision 38, C18). `lifecycle(deleted)`
always revokes that identity and the keys bound to it and stops its watches.
It deletes the Synaplan account only while that cloud alone manages it — all
four hold:

1. the account was provisioned for this identity (`BPROVIDERID = 'external'`,
   and its user details name this cloud and platform user);
2. no other identity row of the account is still active — another cloud,
   another instance, organisation login (`oidc:<issuer>`), Outlook;
3. it has no sign-in of its own — no password, so nobody could have signed
   in to create keys or connect it elsewhere;
4. it is not an administrator.

Otherwise the account stays with the person's other connections, and the
audit row `platform_link.account_kept` names the reason ("also used from
cloud.example.org"). Today's app releases call the generic admin delete
instead (D16); until every release calls `lifecycle`, that endpoint applies
the same rule — for the identity the account was provisioned for — when an
API key calls it, and answers `409 account_in_use` for an account the rule
keeps. A Synaplan administrator deleting a user in the admin screen is
unchanged.

---

## 8. API additions

| Method | Path | Auth | Purpose |
| ------ | ---- | ---- | ------- |
| `GET` | `/api/v1/platform-links/instances/self` | instance | Existing; adds `capabilities` (§8.1) so apps hide what the core lacks, `contract` (`1` from CF5.10, absent before), the stored `app_capabilities`, `delivery_url` and `reachable`, and from CF3.1 `notify_keys` (`[{kid, public_key}]`, `03` §2) |
| `PATCH` | `/api/v1/platform-links/instances/self` | instance | Body `{delivery_url?, oidc_client_id?, app_version?, server_id?, app_capabilities?}` — every field optional, a missing field keeps its value. `server_id` is the cloud's own `instanceid` (§3.1); `app_capabilities` is a list of the names in §8.1 and replaces the stored list; names the core does not know are ignored, so a newer app keeps working with an older core (C1). `delivery_url` is checked by the delivery-origin rule of `03` §2; the answer carries `reachable` from the probe |
| `GET` | `/api/v1/platform-links/sso/status` | bearer + instance | Is this person bound (show the consent card or not) |
| `POST` | `/api/v1/platform-links/sso/bind` | bearer + instance | Write the identity row (mode `sso`) |
| `POST` | `/api/v1/platform-links/sso/background-key` | bearer + instance | Mint `files rag cloud:watch`, label "OpenCloud: cloud.example.org (jdoe) — while you are away"; plaintext once |
| `POST` | `/api/v1/platform-links/identities/adopt` | key + instance | Bring a provisioned person's legacy row into the canonical shape and bind that key (§3.1) |
| `POST` | `/api/v1/platform-links/lifecycle` | instance | `{external_id, event: deleted \| disabled \| enabled}`; `deleted` removes the account only under the rule of §7 |
| `POST` | `/api/v1/admin/users`, `/api/v1/admin/users/{id}/api-keys` | admin key (+ instance) | Existing; with the instance credential they write the canonical row and bind the key (§3.1) |
| `DELETE` | `/api/v1/admin/users/{id}` | admin | Existing; called with an API key it answers `409 account_in_use` for an account the rule of §7 keeps |
| `GET` | `/api/v1/me/platform-links` | session | Existing; adds mode, status, last seen, counts |
| `POST` | `/api/v1/me/platform-links/{id}/turn-off` | session | Turn an organisation-login connection off: identity `paused`, the app's bearer calls answer `403 platform_disconnected`, watches pause (CF5) |
| `POST` | `/api/v1/me/platform-links/{id}/resume` | session | Turn an organisation-login connection back on |
| `DELETE` | `/api/v1/me/platform-links/{id}` | session | Existing disconnect for linked identities and shared keys. Changed: the row stays with `BSTATUS = revoked` (D19), the bound key is revoked, watches pause (`identity_off`). For an identity created by the administrator it answers `403 managed_by_administrator` (§5.3) |
| `PUT` | `/api/v1/platform-links/read-scope` | instance | What a person lets Synaplan read (`07` §5) |

Copy endpoints (`/api/v1/platform-links/deliveries/{id}/…`) are listed in
[`03`](./03_send_to_cloud.md) §4, pickup endpoints
(`/api/v1/platform-links/pickups/{id}/…`) in [`07`](./07_files_into_runs.md)
§11, status, source events and watches in
[`04`](./04_file_state_and_watches.md) §E.1. Full OpenAPI annotations on every
endpoint; `make -C frontend generate-schemas` and `vue-tsc` after each change.

### 8.1 Capabilities, both ways

The core tells the app what it supports (`capabilities` in `instances/self`);
the app tells the core what it supports (`app_capabilities` through `PATCH
instances/self`, row 43). A name appears only once the brief that builds it
is merged; an app hides a feature whose core capability is missing (R11), and
Synaplan offers a cloud only for features its app reports (C21).

| Core capability | Added by | | App capability | Reported by | Synaplan uses it for |
| --------------- | -------- | - | -------------- | ----------- | -------------------- |
| `sso_bind`, `identity_adopt` | CF2.2 | | `deliveries` | CF3.3 / CF3.4 / CF3.5 | Listing the cloud under **Copy to cloud** and as a task output |
| `lifecycle` | CF2.4 | | `pickups`, `read_scope` | CF3.8 / CF3.9 / CF3.10 | Offering the cloud as a `folder_fetch` channel; the "Synaplan may read" line |
| `deliveries` | CF3.1 | | `source_events` | CF4.3 / CF4.4 / CF4.5 | Offering "Keep it up to date" and re-upload requests |
| `pickups`, `read_scope` | CF3.6 | | `watches` | CF5.4 / CF5.5 / CF5.6 | Naming the cloud in the Watched-folders empty state and the Apps card line |
| `cloud_status`, `source_events` | CF4.1 | | `trials`, `file_sets` | CF5.9 (per app) | Showing **Do this for every new file** for chats whose files came from that cloud |
| `watches` | CF5.1 | | | | |
| `trials`, `file_sets` | CF5.8 / CF5.7 | | | | |

---

## 9. Schema (ask recorded)

| Table | Change |
| ----- | ------ |
| `BPLATFORMINSTANCES` | `BDELIVERYURL VARCHAR(512) NULL`, `BREACHABLE TINYINT NULL` (probe result, `03` §2), `BOIDCCLIENTID VARCHAR(191) NULL`, `BAPPCAPABILITIES JSON NULL` (what the app reports, §8.1), `BAPPVERSION VARCHAR(32) NULL`, `BAPPROVEDHOSTS JSON NULL` (approved private hostnames, §6.2), `BSERVERID VARCHAR(64) NULL` (the cloud's own `instanceid`, for adoption — §3.1) |
| `BEXTERNALIDENTITIES` | `BMODE VARCHAR(16) NULL`, `BSTATUS VARCHAR(16) NOT NULL DEFAULT 'active'`, `BREVOKEDAT BIGINT NULL`, `BREADSCOPE JSON NULL` (mirror of the app's read setting, `07` §5) |

Raw idempotent `ADD COLUMN IF NOT EXISTS`; no Schema API (Galera rule 2).

---

## 10. Security notes

- Bind needs the person's live token, the instance credential and a matching
  `azp` (§3). Adoption needs the person's key, the instance credential and
  the server id the instance reported (§3.1).
- Deleting a person in one cloud never deletes an account another connection
  or the person's own sign-in still uses (§7, C18).
- Notifications to the apps are signed with Synaplan's notify key; the key
  pair lives encrypted in `BCONFIG` like the federation key, and only its
  public half leaves Synaplan (`03` §2).
- The background key is scoped, labelled, visible in the person's API keys
  with the *linked platform* badge, and revoked with the identity.
- Scope additions are exact paths; `ApiKeyScopeTest` pins allows and denials
  (C3).
- Nextcloud stores its per-user, admin and shared keys as sensitive config
  values, which Nextcloud encrypts (CF1.3c); ownCloud.online encrypts its
  per-user keys with `ICrypto` (CF1.1). Reading an old plaintext value
  re-saves it encrypted.
- Consent is the bind (organisation login) or the stored consent preference
  (other modes); no Synaplan account is created by a file action without it.
- Every bind, background-key mint, lifecycle event and turn-off writes an
  audit row under the person (`platform_link.*`, like
  `platform_link.reassigned` today).
