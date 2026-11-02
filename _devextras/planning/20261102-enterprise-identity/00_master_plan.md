# Enterprise identity — SAML sign-in, SCIM provisioning, two-factor

Row 35. Umbrella: [`20261102-enterprise-readiness/`](../20261102-enterprise-readiness/00_master_plan.md) (C1–C3).

A company's IT asks three questions before it rolls Synaplan out to 500
people:

1. Can our people sign in with the company account? Many directories (ADFS, Shibboleth, older Okta and Entra apps, the public sector's DFN-AAI) speak **SAML 2.0** and not OIDC.
2. Can our directory create, change and **switch off** accounts on its own, the same day someone leaves? That is **SCIM 2.0** (RFC 7643/7644), the protocol Entra ID, Okta and OneLogin push to an app.
3. Do the few local accounts (admins, service people) have **two-factor**? Public tenders write "2FA" as a hard requirement.

Synaplan answers none of them today. Langdock sells SAML and SCIM in its
Business plan. This track adds all three **on top of the identity model
of row 29** (IAM consolidation). SAML is one more *directory* door. SCIM
is the directory writing into the same users and directory groups. Two-
factor belongs to the password door. Row 29 lists SAML and SCIM as its
non-goals, so this track starts after row 29's IC2–IC6 are merged (§7).

## 1. Findings (checked 2026-11-02 against `main`)

| # | Prio | Finding | Evidence |
| - | ---- | ------- | -------- |
| F1 | P1 | Only OIDC exists as a company sign-in. No SAML code or dependency | `Controller/OidcController.php`, `KeycloakAuthController.php`, `Service/OidcUserService.php`; `composer.json` has no SAML package |
| F2 | P1 | A directory user is matched by mail address and stored with `BPROVIDERID='keycloak'`. The subject (`sub`) of the IdP is not the key. A rename of the mail address in the directory creates a second account | `OidcUserService.php:73-92`; `Entity/User.php` columns `BMAIL`, `BPROVIDERID`, `BINTYPE` |
| F3 | P1 | Directory groups are written only at sign-in from the token's claims. A person removed from a group, or switched off in the directory, keeps access until their next sign-in, and a person who never signs in again is never switched off | `Service/Iam/DirectoryGroupSync.php::shouldRun()`, `sync()` |
| F4 | P1 | No two-factor for local accounts (password, mail link) | no TOTP or WebAuthn code in `backend/src` |
| F5 | P2 | The OIDC access policy (organisation, role, permission → allow or deny with a localized reason) is OIDC-named but protocol-neutral in logic | `Service/Auth/OidcAccessPolicy.php`, `OidcAccessDenialReason.php`, `OidcClaimResolver.php` |
| F6 | P2 | The chart knows `oidc.*` and nothing else for company sign-in | `synaplan-charts/charts/synaplan/values.yaml:278-325` |

## 2. What is fine — do not "fix" it

- OIDC sign-in, Bearer token exchange for Nextcloud / OpenCloud / Desktop, `OIDC_ALLOW_USER_PROVISIONING`, the access policy and its `@oidc` E2E specs. SAML reuses them and does not fork them.
- Groups with `BKIND=directory`, `BEXTERNALSOURCE`, `BEXTERNALID` and group policies. SCIM writes into exactly these rows.
- `UserLifecycleService` as the one creation path (row 29 IC3), and `Service/Iam/Erasure/` for deletion.
- Keycloak in the dev stack (`docker-compose.yml`, `--profile oidc`). It can also act as a SAML IdP and as a SCIM test client through a realm plugin, so no new IdP container is needed.

## 3. Target model

### 3.1 One external identity per person and source

New table `BEXTERNAL_IDENTITIES`:

| Column | Meaning |
| ------ | ------- |
| `BUSERID` | the Synaplan account |
| `BSOURCE` | `oidc:{issuer}`, `saml:{idpEntityId}`, `scim:{clientId}` |
| `BEXTERNALID` | OIDC `sub`, SAML persistent `NameID` (or the configured immutable attribute such as `objectGUID`), SCIM `externalId` |
| `BCREATED`, `BLASTSEEN` | dates |

The key is unique on (`BSOURCE`, `BEXTERNALID`).

- A sign-in matches on the external identity first and falls back to the mail address only once, to adopt an existing account. That fallback is audited (`identity.linked`).
- A changed mail address in the directory updates `BMAIL`; it never creates a second account.
- Migration: raw idempotent `addSql`, the Galera rule. A backfill command, `app:iam:external-identities`, links today's OIDC users at their next sign-in (D2).

### 3.2 SAML 2.0 — one more directory door

```text
/saml/metadata        SP metadata (signed), entity id = publicUrl + /saml/metadata
/saml/login           SP-initiated AuthnRequest (redirect binding)
/saml/acs             Assertion Consumer Service (POST binding)
/saml/slo             Single Logout (optional, D4)
```

- Library: `onelogin/php-saml` 4.x (SAML Toolkits, maintained, 4.3.2 from 2026-05) used directly, not the Symfony bundle (D1). Synaplan signs in with its own cookie tokens (`CookieTokenAuthenticator`), and the bundle's firewall integration would sit beside them.
- The ACS ends in the **same** responder as OIDC (`OAuthLoginResponder`), so cookies, the native hand-off and the redirect checks stay one code path.
- **Security defaults, not options:**
  - signed assertion required;
  - audience and recipient checked;
  - `InResponseTo` bound to the request stored in Redis (5 min);
  - assertion id replay cache (Redis, until `NotOnOrAfter`);
  - clock skew 120 s;
  - SHA-256 or stronger;
  - IdP-initiated sign-in off unless `SAML_ALLOW_IDP_INITIATED=true`.
- **Attributes:** mail, display name, groups, roles. The mapping is configurable, with presets for Entra ID, ADFS, Okta, Keycloak and Shibboleth / DFN-AAI (`docs/IDENTITY.md`).
- **Access policy:** `OidcAccessPolicy` becomes `DirectoryAccessPolicy` with claim sources for OIDC and SAML. OIDC behaviour is unchanged, and its `@oidc` specs stay green.
- **Configuration:** env, like OIDC, because operators and the chart own it. The minimum is `SAML_ENABLED`, `SAML_IDP_METADATA_URL` (or `SAML_IDP_METADATA_XML`), `SAML_SP_CERT` / `SAML_SP_KEY` (secret) and `SAML_PROVIDER_LABEL`; mapping and policy are optional. The module is a `FeatureModule` (`Module/Auth/SamlModule`), absent without metadata.
- The login page shows "Sign in with {label}" beside or instead of OIDC. With both configured, both buttons show; auto-redirect applies to one of them only (D5).

### 3.3 SCIM 2.0 — the directory switches people on and off

```text
/scim/v2/ServiceProviderConfig   /scim/v2/ResourceTypes   /scim/v2/Schemas
/scim/v2/Users       GET (filter, startIndex, count)  POST
/scim/v2/Users/{id}  GET  PUT  PATCH  DELETE
/scim/v2/Groups      GET (filter, excludedAttributes=members)  POST
/scim/v2/Groups/{id} GET  PATCH (members add/remove, displayName)  DELETE
```

- **Auth:** one long-lived bearer token per SCIM client, generated in Admin → Authentication → Provisioning, shown once, stored hashed (`BSCIM_CLIENTS`: name, token hash, directory source, created, last used, last error). Revoke is one click.
- **Users:**
  - `userName` = mail; `externalId` → `BEXTERNAL_IDENTITIES`.
  - `active=false` → account status `suspended`: sign-in refused, sessions and API keys stop at once, data kept. `active=true` restores.
  - `DELETE` → `suspended` plus erasure after `SCIM.DELETE_GRACE_DAYS` (default 30, D3).
  - A suspended user is still returned by `GET` and by filter (an Entra requirement).
- **Groups:**
  - Create, rename and delete directory groups with `BEXTERNALSOURCE=scim:{clientId}`.
  - Members add and remove (several adds in one `PATCH`), idempotent.
  - `displayName` is unique per source, which Entra requires.
- **Compatibility rules from Entra and Okta:**
  - `op` is case-insensitive;
  - `active` may arrive as the string `"False"`;
  - `members[value eq "…"]` path filters;
  - zero results is `200` with an empty `ListResponse`, never `400`;
  - `application/scim+json`;
  - values are stored as sent;
  - at least 25 requests per second per client.
- **One source of truth per group:** when a SCIM client owns a directory, OIDC/SAML claim-based group sync for the same source is skipped. The sign-in token still signs in, but it no longer writes memberships (D6). This ends F3.
- **No library:** no maintained PHP SCIM server exists. The surface is small and written here: one controller per resource, a filter parser for `eq` / `and` / `or` / `co` / `sw` on the attributes Entra and Okta use, and a PATCH applier.

### 3.4 Two-factor for local accounts

- TOTP (RFC 6238), any authenticator app, plus ten single-use recovery codes. Library: `spomky-labs/otphp` (D7).
- Sign-in with password → `mfa_required` with a short-lived challenge → code → tokens. The same challenge works for the web, Desktop and the mobile app (Bearer flow).
- Profile → Security: "Turn on two-factor" (QR code, confirm with a code, show the recovery codes once). Turning it off asks for a code.
- Admin → Authentication: `AUTH.REQUIRE_2FA` = `off` (default) / `admins` / `everyone with a password`. Affected people enrol at their next sign-in. Company sign-in accounts are never asked, because MFA is the directory's job.
- Admin recovery: People → user → "Reset two-factor". It is audited, and the person gets a mail.
- Passkeys (WebAuthn, `web-auth/webauthn-lib`) are phase 2 (EI9, D8).

### 3.5 What the administrator sees (U7, five questions)

Admin → Authentication → **Provisioning**:

- one card per SCIM client: name, token created / last used, the last ten calls with result, users and groups it owns, and Revoke;
- the SAML card: IdP entity id, certificate expiry date (warning 30 days before), the last sign-in, and "Download SP metadata".

People → user shows "Managed by {directory}" with the source. Fields the
directory owns (mail, name, active) are read-only there, with the
sentence "Change this in {directory}; Synaplan updates within minutes."

## 4. Decisions

- [ ] **D1 (EI2).** `onelogin/php-saml` ^4.3 directly with an own controller. The alternative, `nbgrp/onelogin-saml-bundle` v3, integrates with the Symfony firewall and duplicates Synaplan's token cookies. A new composer dependency is the owner's call.
- [ ] **D2 (EI0).** `BEXTERNAL_IDENTITIES` table as in §3.1, plus a backfill at next sign-in. *Alternative:* keep matching by mail — rejected, because F2 creates duplicates on rename.
- [ ] **D3 (EI4).** SCIM `DELETE` suspends, then erases after 30 days (configurable, 0 = immediately). Entra already soft-deletes first.
- [ ] **D4 (EI2).** Single Logout in the first release or later. Many IdPs are fine without it; ADFS customers ask.
- [ ] **D5 (EI2).** OIDC and SAML may both be configured at the same time (both buttons), or only one company sign-in per install.
- [ ] **D6 (EI5).** When SCIM owns a directory source, sign-in claims no longer write that source's memberships.
- [ ] **D7 (EI1).** `spomky-labs/otphp` for TOTP. A new composer dependency, owner's call. `scheb/2fa-bundle` is rejected for the same firewall reason as D1.
- [ ] **D8 (EI9).** Passkeys in 2027 or not at all.
- [ ] **D9 (EI3).** A `@saml` Playwright project against the dev Keycloak (realm import with a SAML client) as a CI job. CI change, owner's call. Without it, SAML is proven in PHPUnit with signed fixtures only.

## 5. Tests and journeys

### 5.1 Tests

| Id | Layer | What it proves | Step |
| -- | ----- | -------------- | ---- |
| T1 | PHPUnit | A rename of the mail address in the IdP signs into the same account (OIDC and SAML); an adoption by mail happens once and is audited | EI0, EI2 |
| T2 | PHPUnit | SAML rejects: an unsigned assertion, a wrong audience, an expired one, a replayed id, an unknown `InResponseTo`, a signature-wrapping fixture, SHA-1 | EI2 |
| T3 | PHPUnit | SCIM conformance: recorded Entra and Okta request sequences (create, filter, `active` false as string, group add 20 members, remove one, rename, delete) replayed against the controller give the documented status codes and bodies | EI4, EI5 |
| T4 | PHPUnit | A suspended user: password sign-in, OIDC, SAML, refresh, API key, MCP, Desktop and widget owner are all refused (extends row 29 T3) | EI4 |
| T5 | PHPUnit | TOTP: right code, wrong code, a reused code inside its window, a recovery code used twice, the admin requirement enforced at sign-in, SSO accounts never asked | EI1 |
| T6 | Load | 25 SCIM requests per second for 60 s, p95 < 300 ms, no deadlock on Galera (`make perf-runtime` scenario) | EI6 |
| T7 | External | Microsoft Entra SCIM Validator and Okta's SCIM 2.0 test suite run against a tunnel to the dev stack; results logged in STATUS | EI6 |
| E1 | E2E `@saml` | Keycloak SAML: button, sign-in, first-time provisioning, group mapping, denial sentence (D9) | EI3 |
| E2 | E2E `@ci` | Two-factor: turn on, sign out, sign in with a code, use a recovery code, admin reset | EI1 |

### 5.2 Journeys

| Id | Journey | Profile |
| -- | ------- | ------- |
| J-EI-1 | An admin pastes the Entra metadata URL in the chart values, deploys, and downloads the SP metadata from Admin → Authentication → Provisioning. A colleague signs in with "Sign in with Contoso" and lands in the chat with the groups from Entra | Operator, integrator |
| J-EI-2 | An admin creates a SCIM token ("Entra production"), copies it once, and pastes it with the base URL into Entra. Entra provisions 3 users and 1 group. The card shows "3 people, 1 group, last call 1 min ago" | Operator |
| J-EI-3 | In Entra, one person is disabled. Within the provisioning cycle their Synaplan sign-in says "Your account was switched off by Contoso's directory. Ask your IT team." Their API key stops. People shows "Switched off by Entra production" | Admin, user |
| J-EI-4 | An admin sets "Two-factor for admins". At the next sign-in the admin enrols with an authenticator app, saves the recovery codes, signs out and in with a code | Admin |
| J-EI-5 | An admin revokes the SCIM token from the same card. The card says "Entra can no longer change people in Synaplan. People it created keep their accounts." The next Entra call gets 401 | Admin |

## 6. Steps

| Step | Commit subject | Release class | Depends on | Gate |
| ---- | -------------- | ------------- | ---------- | ---- |
| EI0 | `refactor(iam): key directory accounts on their external identity, not the mail address` — `BEXTERNAL_IDENTITIES` (migration, eraser), OIDC matches on `sub`, one audited adoption by mail; `DirectoryAccessPolicy` extracted from `OidcAccessPolicy` with no behaviour change; T1 (OIDC part) | backend-only | row 29 IC3, D2 | `make ci-local`; `npm run test:e2e:oidc` |
| EI1 | `feat(auth): two-factor sign-in for password accounts` — TOTP + recovery codes, `mfa_required` challenge in the web, Bearer and native flows, Profile → Security, `AUTH.REQUIRE_2FA`, admin reset with mail, audit events; T5, E2 | backend-only + ota-candidate + **store-required check**: the native login must understand `mfa_required` before any account enables it. Ship the app update first and classify with `scripts/mobile-impact.mjs` | D7 | `make ci-local && make test-e2e` |
| EI2 | `feat(auth): sign in with a SAML 2.0 directory` — `SamlModule`, `/saml/*`, security defaults of §3.2, attribute presets, `DirectoryAccessPolicy` for SAML, directory groups from SAML attributes, login button; T1 (SAML part), T2 | backend-only + ota-candidate (login button) | D1, D4, D5, EI0 | `make ci-local && make test-e2e` |
| EI3 | `test(e2e): SAML sign-in against the dev Keycloak` + `docs(auth): SAML guides for Entra ID, ADFS, Okta, Keycloak and DFN-AAI` in `docs/IDENTITY.md` (created by row 29 IC6); E1 | no-app-impact | D9, EI2 | `make test-e2e`; `@saml` project |
| EI4 | `feat(iam): SCIM 2.0 user provisioning` — discovery endpoints, `/Users` with filter and pagination, PATCH applier, suspend / restore / delete grace, `BSCIM_CLIENTS` tokens (hashed), rate limit per client; T3 (users), T4 | backend-only | D3, EI0 | `make ci-local` |
| EI5 | `feat(iam): SCIM 2.0 groups into directory groups` — `/Groups`, members PATCH, unique `displayName` per source, claim-sync skip for SCIM-owned sources; T3 (groups) | backend-only | D6, EI4 | `make ci-local` |
| EI6 | `test(iam): SCIM against Entra's validator and Okta's suite` — T6, T7 logged; fixes found are named in the PR | backend-only | EI5 | `make ci-local`; T7 in STATUS |
| EI7 | `feat(admin): Provisioning — who manages accounts here` — the SCIM client cards and the SAML card of §3.5, token create (shown once) and revoke, certificate expiry warning, read-only directory fields on People with the source sentence; J-EI-2, J-EI-3, J-EI-5 | ota-candidate | EI4, EI2 | `make ci-local && make test-e2e` |
| EI8 | `feat(synaplan): SAML and SCIM settings` in `synaplan-charts` — `saml.*` (metadata URL or XML, SP cert/key via `secretRef`, mapping, label), `scim.enabled`, an ingress note for `/scim/v2` and `/saml`, README template linking `docs/IDENTITY.md`; `helm template` with an Entra values file | — (chart) | EI2, EI4 | `make all` |
| EI9 | `feat(auth): passkeys for password accounts` — WebAuthn register and sign-in, beside TOTP | backend-only + ota-candidate + store-required check | D8, EI1 | `make ci-local && make test-e2e` |

EI1 and EI7 carry the five exit bullets of `docs/UX_CONTRACT.md` §6
before their first `.vue`:

- **Journeys walked:** J-EI-4 (EI1); J-EI-2, J-EI-3 and J-EI-5 (EI7).
- **Ten-second findability:** two-factor sits under Profile → Security; provisioning under Admin → Authentication → Provisioning; "Managed by {directory}" on the People row.
- **Consequence copy in five locales:** revoke (J-EI-5 sentence); suspend (J-EI-3 sentence); requiring two-factor ("Admins will set up two-factor at their next sign-in. Nobody is signed out now.").
- **Empty, error and flag-off states:** no SCIM client yet → "Connect your directory so people are added and switched off automatically." + **Create a token**; an expired IdP certificate is a red line with its date; without SAML or SCIM configured the Provisioning tab is absent (U11).
- **Dark, V2 and 320 px.**

## 7. Order

```text
row 29   IC2 → IC3 → IC6 (IDENTITY.md)        must be merged first
this     EI0 → EI1 ∥ EI2 → EI3 → EI4 → EI5 → EI6 → EI7 → EI8   (EI9 later)
```

EI1 (two-factor) does not depend on EI0 and can start right after row
29 IC3. It is the cheapest tender item.

## 8. Risks

| Risk | Mitigation |
| ---- | ---------- |
| SAML XML signature attacks | Library defaults plus the T2 fixtures (wrapping, unsigned, SHA-1); signed assertions required; a pentest scope item in ER3 |
| SCIM suspends the wrong people (bad mapping in the directory) | Suspend, not erase; 30-day grace; the provisioning card lists the last ten calls; restore is `active=true` or one click in People |
| Two-factor locks out the only admin | Recovery codes; `app:admin:reset-2fa` console command for the operator, audited |
| Native app without `mfa_required` support | Enforced only for people who enabled it or are required; the app release ships before EI1 is deployed; EI1 is store-required-checked |
| SCIM rate on Galera (many small writes) | T6; writes batched per PATCH in one transaction; no table locks |

## 9. Non-goals

- LDAP / Active Directory bind directly from Synaplan. Keycloak or Entra Connect federates LDAP into OIDC/SAML, which is the documented path (`docs/IDENTITY.md`).
- Multi-tenant identity (several unrelated directories mapping into separate organisations in one install).
- SCIM *outbound* (Synaplan pushing users elsewhere).
- Replacing OIDC or the access policy semantics.

## 10. Change log

| Date | Change |
| ---- | ------ |
| 2026-11-02 | Plan written from Microsoft's SCIM and gallery requirements, the SAML Toolkits PHP library state, row 29's identity model, the chart values and a read of the OIDC, directory-sync and user code on `main`. No code changed. |
