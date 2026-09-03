# Sprint 2 — Nextcloud app: `link` mode

**Track 6 (`synaplan-nextcloud/`), sprint 2 of 3.** Steps `NC10`–`NC17`.

**Goal:** A Nextcloud user with an existing Synaplan account clicks **Connect
Synaplan** in personal settings, signs in once, confirms, and the next Files
action runs as that account. Admins choose the mode per instance; `shared`
and `provision` stay byte-identical.
**Depends on:** S1 (`NC1`–`NC7`) merged and reachable (locally: the
`fake-instance.sh` target, `PLATFORM_LINKS.ENABLED = 1`). Master plan
decisions 1, 7, 9, 11; §11 rows 3, 5.
**Unlocks:** S3 parity (the ownCloud.online app copies this PHP).
**Repos:** `synaplan-nextcloud/` only. App version `1.5.0 → 1.6.0`.
**Flag:** none in the app — the mode is admin configuration (`mode` app value).
Existing installs never change mode on upgrade.

## 0. Why this sprint exists

Today `UserAccountService::provisionAndMint()` is the only way a Nextcloud
user gets a personal key, and it refuses when the email is taken. `link` mode
adds the second way — connect the account that already exists — without
touching how the two shipped modes resolve a key.

## 1. Current code to read first

| Path | Why |
| ---- | --- |
| `lib/Service/SynaplanConfig.php` (`isPerUserAccountsEnabled()`, `getAdminApiKey()`, `getInstanceId()`) | Mode accessor lives here; `per_user_accounts` stays the storage for old installs |
| `lib/Service/UserAccountService.php` (`getCurrentUserApiKey()`, `provisionAndMint()`, `USER_KEY_PREF`, `USER_ACCOUNT_ID_PREF`, `CONSENT_PREF`) | Key resolution and the prefs the linked key reuses |
| `lib/Service/SynaplanClient.php` (`getApiKey()`, `request()` 401 self-heal, `isUnauthorized()`) | Where a revoked linked key must clear prefs instead of re-minting |
| `lib/Controller/ConsentController.php`, `lib/Controller/SettingsController.php`, `lib/Service/AdminAiUsersService.php` | Gate API, admin settings API, activated-users table |
| `src/components/AiConsentGate.vue` (`blocked`, `activate()`, emits `granted`) | Becomes the two-option gate |
| `src/components/AdminSettings.vue` (`perUserAccounts`, `enableMemories`, `loadAiUsers()`, `deactivateUser()`) | Mode selector, Register button, badges |
| `appinfo/info.xml` (`<settings><admin>…</admin-section>`), `appinfo/routes.php` | Personal section and the three new routes |
| `lib/Settings/SynaplanAdmin.php`, `lib/Settings/SynaplanSection.php`, `src/settings.ts` | Pattern for the personal settings panel and its entry file |
| `lib/Listener/UserDeletedListener.php` (`deleteRemoteAccount()`) | Linked accounts must **not** be deleted on NC user removal |
| `l10n/en.json`, `l10n/de.json` | App translations |
| `tests/Unit/Service/UserAccountServiceTest.php`, `SynaplanClientTest.php`, `tests/Unit/Controller/SettingsControllerTest.php`, `tests/stubs/` | Existing tests that must stay green (C1, C2) |
| `synaplan/_devextras/planning/202609_more_nextcloud/01_sprint_1_core_handshake.md` §2.2–2.3 | The exact API the app calls |

## 2. Developer steps

### 2.1 `NC10` — Mode enum in `SynaplanConfig` (partner-app)

```php
public const MODE_SHARED = 'shared';
public const MODE_PROVISION = 'provision';
public const MODE_LINK = 'link';

public function getMode(): string
{
    $mode = $this->config->getAppValue(Application::APP_ID, 'mode', '');
    if ($mode === self::MODE_LINK || $mode === self::MODE_PROVISION || $mode === self::MODE_SHARED) {
        return $mode;
    }
    return $this->isPerUserAccountsStored() ? self::MODE_PROVISION : self::MODE_SHARED;
}
```

- `isPerUserAccountsEnabled()` returns `getMode() !== MODE_SHARED` (link implies per-user key lookup); `SettingsController::saveSettings()` writes both `mode` and `per_user_accounts` so a downgrade to 1.5 keeps working.
- New app values: `link_instance_id`, `link_instance_secret` (stored through `OCP\Security\ICrypto::encrypt()`), `link_auto_provision` (`'0'`). Accessors `getLinkInstanceId()`, `getLinkInstanceSecret()`, `isLinkAvailable()` (mode link **and** registration present), `isAutoProvisionEnabled()` (`link_auto_provision === '1'` **and** admin key set — decision §11.3).

### 2.2 `NC11` — `PlatformLinkService` + `LinkController` (partner-app)

`lib/Service/PlatformLinkService.php` (talks to Synaplan via `IClientService`,
like `UserAccountService::adminRequest()`; never via `SynaplanClient`):
`registerInstance(?string $adminKey)`, `instanceStatus()`, `exchange(string $code)`,
`disconnect(IUser $user)`.

`lib/Controller/LinkController.php`, routes in `appinfo/routes.php`:

```php
['name' => 'link#start',      'url' => '/link/start',      'verb' => 'GET'],
['name' => 'link#callback',   'url' => '/link/callback',   'verb' => 'GET'],
['name' => 'link#disconnect', 'url' => '/link/disconnect', 'verb' => 'POST'],
['name' => 'link#status',     'url' => '/api/v1/link/status', 'verb' => 'GET'],
```

- `start` (`@NoAdminRequired`): `state = bin2hex(random_bytes(16))` stored in `ISession` key `synaplan_link_state` with `synaplan_link_state_at`; 302 to `<baseUrl>/connect/platform?client=nextcloud&instance=<link_instance_id>&uid=<uid>&state=<state>&redirect_uri=<urlGenerator absolute link#callback>`.
- `callback` (`@NoAdminRequired @NoCSRFRequired` — a cross-site GET; `state` is the CSRF check): reject when `state` is missing, differs from the session value, or is older than 10 minutes; delete it before anything else. `exchange($code)` posts `{ instance_id, instance_secret, code }` to `POST /api/v1/platform-links/exchange`; on success write `synaplan_user_api_key`, `synaplan_user_id`, and new prefs `synaplan_link_kind = 'linked'`, `synaplan_link_email`, `synaplan_linked_at`, plus `ai_consent = '1'` (a link **is** consent, so the activated-users table keeps working). 302 to personal settings `?linked=1`; on failure `?link_error=<state|exchange|instance_pending>`. The code is never logged or echoed.
- `disconnect`: `DELETE <baseUrl>/api/v1/apikeys/{synaplan_user_key_id}` with the user's own key (allowed by `ApiKeyScope::isSelfRevoke()`), then delete the prefs above. Store `synaplan_user_key_id` at callback time for this.
- `status` → `{ mode, link_available, auto_provision, linked: { email, since } | null, kind: 'linked' | 'provisioned' | null }`.

### 2.3 `NC12` — Key resolution and 401 handling (partner-app)

- `UserAccountService::resolveKeyForUser(IUser $user): ?string`: `shared` → `null` (caller uses the admin key as today); `provision` → stored key or `provisionAndMint()` after consent (unchanged code path); `link` → stored key or `null` — **never** provisions implicitly. `getCurrentUserApiKey()` delegates.
- `provisionForLinkMode(IUser $user)` — only reachable from `consent#setConsent` with `{ create_account: true }` when `isAutoProvisionEnabled()`; writes `synaplan_link_kind = 'provisioned'`.
- `SynaplanClient::request()`: on 401 in `link` mode, call `userAccounts->clearCurrentUserApiKey()` **and** `clearLinkPrefs()`, do not retry, rethrow — the UI shows the gate again. `provision` mode keeps the existing clear-and-retry.
- `UserDeletedListener`: skip `deleteRemoteAccount()` when `synaplan_link_kind === 'linked'`; instead call `disconnect()` best-effort (decision 9: linking never deletes a Synaplan user).

### 2.4 `NC13` — Two-option gate + personal settings (partner-app)

- `AiConsentGate.vue`: reads `api#clientConfig` (extended with the `link#status` fields). In `link` mode shows **Connect my Synaplan account** (→ `link/start`) and, only when `auto_provision` is true, **Create one for me** (→ `consent#setConsent { granted: true, create_account: true }`). In `provision` mode the existing single button is unchanged.
- `lib/Settings/SynaplanPersonal.php` (`ISettings`, section `synaplan`), `<personal>OCA\SynaplanIntegration\Settings\SynaplanPersonal</personal>` and `<personal-section>` in `info.xml`, entry `src/personal-settings.ts`, `src/components/PersonalSettings.vue`: status (Not connected / Connected as *email* since *date*), Connect / Disconnect, and the `?linked=1` / `?link_error=` toasts. Copy from master plan §5.2 in `l10n/en.json` + `l10n/de.json` (add `es`, `fr`, `tr` files if the app gains them in this release).

### 2.5 `NC14` — Admin settings for `link` mode (partner-app)

- `AdminSettings.vue`: the `perUserAccounts` checkbox becomes a three-way selector `shared | provision | link` bound to `mode` (`SettingsController` maps it as in `NC10`). In `link` mode the admin key field is labelled optional ("needed for auto-provision and the activated-users table").
- **Register this instance** → `settings#registerInstance` (`POST /api/v1/settings/register-instance`, admin only) → `PlatformLinkService::registerInstance()` posts `{ client: 'nextcloud', host: <NC host>, redirect_uris: [<absolute link#callback>] }` to `POST /api/v1/platform-links/instances` with the admin key when set (→ `active`) or anonymously (→ `pending`; the panel shows "Waiting for approval by the Synaplan administrator" and polls `GET /api/v1/platform-links/instances/self`). Stores `link_instance_id` + encrypted secret; shows status, host, and a **Forget registration** action.
- `link_auto_provision` toggle, disabled without an admin key.
- Activated-users table (`AdminAiUsersService::list()` gains `kind`): badge **Linked** / **Provisioned**; "Deactivate" on a linked user calls `disconnect()` for that uid instead of `deactivateUser()`.

### 2.6 `NC15` — Email conflict becomes a link offer (partner-app)

`provisionAndMint()` (both the `provision` path and `provisionForLinkMode()`)
catches HTTP 409 from `POST /api/v1/admin/users` (the
`UserProvisioningConflictException` response) and throws
`EmailConflictException`. `consent#setConsent` maps it to
`{ conflict: true, link_url: <link#start> }` **only when** `isLinkAvailable()`;
the gate then shows "An account with this email already exists — connect it".
Without a registered instance the response is today's error (C2, §11.5).

### 2.7 `NC16` — Release: migration note, changelog, App Store (partner-app)

- `CHANGELOG.md` 1.6.0: three modes; upgrade note "existing `provision` installs switched to `link` keep working: users keep their provisioned key until they choose **Connect my Synaplan account**; new users see the two-option gate" (decision 9). No `occ` migration needed — `mode` unset resolves to the old behaviour.
- `info.xml`: version `1.6.0`, description bullet "Connect an existing Synaplan account", personal settings entries, new screenshot of the gate and the personal section.
- App Store checklist: `make lint && make test && make build`, `make appstore` (signed tarball), release notes in `en` + `de`, verify against Nextcloud 30 and 34 (the `<nextcloud min-version="30" max-version="34"/>` range), confirm `docs/plugins.md` (S3) is live before the store release is published (master plan §8.2).

### 2.8 `NC17` — Tests (partner-app)

New: `tests/Unit/Service/SynaplanConfigTest.php` (mode fallback matrix: unset +
`per_user_accounts` 0/1, explicit values, `isLinkAvailable()` needs both mode
and registration), `tests/Unit/Controller/LinkControllerTest.php`,
`tests/Unit/Service/PlatformLinkServiceTest.php`. Extended:
`UserAccountServiceTest.php`, `SynaplanClientTest.php`,
`SettingsControllerTest.php` (mode ↔ `per_user_accounts` mapping,
`registerInstance` admin-only). Existing shared/provision tests are not edited.

## 3. Tests and invariants

| Invariant | Proof in this sprint |
| --------- | -------------------- |
| C1 | Every pre-existing test in `tests/Unit/` passes unedited; `SynaplanConfigTest::testUnsetModeResolvesToLegacyBehaviour`; `UserAccountServiceTest::testSharedModeResolvesNull`, `testProvisionModeStillProvisionsAfterConsent` |
| C2 | `UserAccountServiceTest::testProvisionModeEmailConflictStillThrows` — no `link_url` when `isLinkAvailable()` is false |
| C3 | `LinkControllerTest::testCallbackNeverCallsAdminUsersApi` — the fake client records no `POST /api/v1/admin/users` during a link |
| C5 | `LinkControllerTest::testCallbackRejectsMissingState`, `testCallbackRejectsForeignState`, `testCallbackRejectsStaleState`, `testStateIsSingleUse`; the code itself is proven single-use by S1 |
| C7 | `provisionForLinkMode()` still sends `source` + `external_id`, so `BUSERDETAILS.external_*` is written by the core as before |

More: `SynaplanClientTest::testLinkMode401ClearsPrefsAndDoesNotRemint`,
`testProvisionMode401StillRetriesOnce`; `PlatformLinkServiceTest::testExchangePostsInstanceCredentialsAndCode`,
`testSecretIsNeverLogged`; `LinkControllerTest::testDisconnectRevokesOwnKeyThenClearsPrefs`;
`AdminAiUsersServiceTest::testListReportsKindLinkedOrProvisioned`. `make lint`,
`make test`, `make build` green.

## 4. Exit criteria / demo

1. Fresh install in `link` mode, instance registered with the admin key: user `jdoe` (existing Synaplan account) clicks Connect Synaplan, signs in, confirms, is redirected back "Connected as jdoe@…", then summarizes a file — the Synaplan request log shows the key `Nextcloud: <host> (jdoe)` and the answer uses that user's knowledge (master plan §10.1).
2. Disconnect in Synaplan → next Files action shows the gate; Disconnect in Nextcloud → the key is gone from Synaplan's API keys list (§10.2).
3. Existing `provision` install switched to `link`: provisioned users keep working; a new user sees the two-option gate; with auto-provision off only one button (§10.3).
4. `provision` mode, taken email: unchanged hard failure (C2). `link` mode, taken email on "Create one for me": link offer appears.
5. Anonymous registration shows as pending in Synaplan; a link attempt before approval shows `link_error=instance_pending`.

## 5. Step table

| Step | PR title (Conventional Commit) | Class | Depends on |
| ---- | ------------------------------ | ----- | ---------- |
| NC10 | `feat(link-mode): add shared/provision/link mode enum to SynaplanConfig` | partner-app | S1 |
| NC11 | `feat(link-mode): add link start, callback and disconnect routes with state check` | partner-app | NC10 |
| NC12 | `feat(link-mode): resolve linked keys and clear prefs on 401 without re-minting` | partner-app | NC11 |
| NC13 | `feat(link-mode): two-option AI gate and personal Synaplan settings section` | partner-app | NC12 |
| NC14 | `feat(link-mode): mode selector, instance registration and linked badges in admin settings` | partner-app | NC11 |
| NC15 | `feat(link-mode): offer to link an existing account on email conflict` | partner-app | NC13, NC14 |
| NC16 | `chore(release): 1.6.0 changelog, info.xml and App Store checklist` | partner-app | NC15 |
| NC17 | `test(link-mode): cover mode fallback, state handling, 401 and conflict paths` | partner-app | NC15 |
