# Findings: authz-resources

Area: authorization / multi-tenancy (IDOR, privilege escalation, mass assignment) for everything
except chats / messages / files / projects / shares / exports / bundles.
Commit a2e57b08dc79e146927471224c6b6426619bddc4. All paths below are relative to
`/Users/cristiangrxs/Workspace/metadist/synaplan/backend/src/` unless they start with another root.

Test identities (registered by me, verified through Mailhog):
- u1 = `audit-authzB-u1@example.com`, id 4915, level NEW (attacker)
- u2 = `audit-authzB-u2@example.com`, id 4925, level NEW (victim)
- demo = seeded demo user, id 2, level PRO (read-only use)

Evidence files: `SCRATCH/authzB/xt1.txt` (cross-tenant batch), `SCRATCH/authzB/adminprobe_u1.jar.txt`
(admin probe), helper scripts in `SCRATCH/authzB/`.

Result in one line: no direct IDOR, no privilege escalation and no mass-assignment path was found in
this area. Three lower-severity issues are reported; the ownership model is applied consistently.

---

## 1. Entry points enumerated

- `/api/v1/admin/*`: 118 routes, 130 method/route combinations, 30+ controllers
  (`Controller/Admin*Controller.php`, `FederationAdminController`, `ResearchAdminController`,
  `AiLiteracy*`, `FreeApi*`, `VectorStats*`, `SetupController`).
- `/api/v1/user/{userId}/plugins/*`: 82 routes (synaform, synafastbill, castingdata, synamail, synascriber).
- Agents and agent templates, agent triggers, agent knowledge folders, agent publish review.
- Saved tasks, saved prompts, prompts (`PromptController`), memories (`UserMemoryService`).
- RAG: `/api/v1/rag/*` (search, similar, passage), vector stats.
- Widgets owner API (`WidgetController`), widget summaries, widget operators (realtime).
- Groups (`/api/v1/groups/*`, `/api/v1/admin/groups/*`), IAM subjects directory (`/api/v1/iam/subjects`).
- Connections: m365, dropbox, cloud (`CloudCallerResolver`, `CloudDeliveryService`).
- MCP server configs, MCP OAuth, MCP tool permissions.
- Compute workspaces, media / media jobs, meeting notes / opendesk, approvals, url-watches,
  inbound-email-handlers, channels and channel notices, apps, integrations (WordPress), custom tools,
  API keys, ai-providers, research runs, usage, profile, `/api/v1/me`, platform links, desktop, voice,
  message digests.
- Cross-cutting: `config/packages/security.yaml` (firewalls, 78 PUBLIC_ACCESS rules),
  `Entity/User.php::getRoles()`, `Security/Voter/IamVoter.php`, `Service/Iam/AccessGate.php`,
  `ApiKeyScopeSubscriber` / `ApiKeyScope`, realtime channel authorizers, `SignupAdmission`,
  `LevelGrantService`, `config/packages/api_platform.yaml`.

---

## 2. Findings

### Finding 1: OAuth connection callback is not bound to the initiating session (M365, Dropbox, MCP OAuth)

- Severity: Medium
- Status: Confirmed by code trace (complete path, no guard). Not reproduced on the running stack: see
  "Reproduction".

**Where**
- `backend/config/packages/security.yaml:169-171`: `/api/v1/connections/m365/callback`,
  `/api/v1/connections/dropbox/callback` and `/api/v1/mcp-servers/oauth/callback` are `PUBLIC_ACCESS`.
- `Controller/MicrosoftConnectionController.php:102-109` (start, passes `(int) $user->getId()`),
  `:132-155` (callback, calls `completeConsent($code, $state)` with no user or session check).
- `Controller/DropboxConnectionController.php:100-107`, `:130-151` (same shape).
- `Service/Microsoft/MicrosoftConnectionService.php:37-50`, `Service/Dropbox/DropboxConnectionService.php:38-51`.
- `Service/OAuth/OAuthConsentService.php:47-65` (state carries `owner_id` and a PKCE nonce; the verifier
  is cached server-side), `:73-110` (`completeConsent` takes the owner from the state and stores the
  tokens on that owner's `Connection`), `:115-133` (`consumeVerifier` compares only state owner to
  cached owner).
- `Service/OAuth/OAuthStateService.php:43-100` (HMAC signature, 600 s TTL at `:27`, provider check; nothing
  ties the state to a browser).
- Sibling: `Controller/McpOAuthController.php:126` and `Service/Mcp/McpOAuthConsentService.php:62`,
  `:143`, `:154-173` (owner_id and server_id from state).

**What**
The signed `state` proves that Synaplan issued it and for which owner, but not that the browser
finishing the flow is the browser that started it. The callback is public and never looks at the
session. An attacker starts a consent flow for their own account, does not complete it, and sends the
provider's authorization URL to a victim. When the victim signs in and consents at the provider, the
provider redirects the victim's browser to the Synaplan callback with a `code` for the victim's
provider account and the attacker's `state`. `completeConsent` then stores the victim's refresh and
access tokens on the attacker's Connection. PKCE does not help: the verifier lives in Synaplan's cache
keyed by the attacker's state, so the server completes the exchange itself.

Where the codebase does it right: every other connection route (`list`, `disconnect`, `start`) resolves
the user from the session and scopes by owner (`ConnectionRepository` owner filters); the state itself
is correctly signed, expiring and provider-scoped.

**Preconditions**
- The provider is configured on the installation (M365 or Dropbox client id/secret; for MCP, a server
  with OAuth).
- Attacker has any authenticated account (level NEW is enough to reach `start`).
- Victim follows a link and consents at the real provider within 600 s. The consent screen shows the
  real Synaplan application name, so it looks legitimate. `prompt=consent`
  (`Service/Microsoft/MicrosoftOAuthConfig.php:154`) is a URL parameter the attacker can remove, so a
  victim who consented before may see no prompt at all.

**Reproduction**
Could not be executed end to end, for two reasons that the brief forbids working around:
the provider is not configured locally and the flow needs traffic to a third party.

```
GET /api/v1/connections/m365/start        (as u1)
-> 503 {"error":"OAuth provider \"m365\" is not configured on this installation"}
GET /api/v1/connections/dropbox/start     (as u1)
-> 503 (same message, provider "dropbox")
```

Steps that would confirm it on a configured stack:
1. As attacker: `GET /api/v1/connections/m365/start`, copy the returned authorization URL.
2. In a different browser profile with no Synaplan session (or signed in as another user), open that
   URL and consent with a second Microsoft account.
3. Observe the callback returning the success redirect, then as attacker
   `GET /api/v1/connections` shows an M365 connection bound to the second Microsoft account.

Missing to reach "reproduced": a locally configured M365/Dropbox app or a stub token endpoint.

**Impact**
The attacker's Synaplan account gains a live connection to the victim's external account with the
scopes the application requests: M365 `Mail.Read`, `Mail.Send`, `Calendars.ReadWrite`, `User.Read`,
`offline_access` (`MicrosoftOAuthConfig.php:57`); Dropbox `account_info.read`, `files.content.write`
(`Service/Dropbox/DropboxOAuthConfig.php:43`). Through agents and tools the attacker can read the
victim's mail, send mail as the victim, and edit their calendar until the grant is revoked at the
provider. The victim does not need a Synaplan account.

**CVSS 3.1**: `AV:N/AC:L/PR:L/UI:R/S:U/C:H/I:L/A:N` = 6.3 (7.6 if scored `S:C`, since the affected data
lives at the external provider). **CWE-352** (CSRF), related CWE-384.

**Fix**
Bind the flow to the initiating browser and check it at the callback:
- In `start`, set a short-lived, HttpOnly, SameSite=Lax cookie holding a random nonce and put its hash
  in the state extras.
- In the callback, require that cookie and compare it to the state before `completeConsent`; reject
  otherwise. Additionally, when a session is present, require `state.owner_id === current user id`.
- Apply the same change to `McpOAuthController::callback`.

```php
// OAuthConsentService::completeConsent(string $code, string $state, string $browserNonce)
$payload = $this->state->verify($provider, $state);
if (!hash_equals($payload['browser'] ?? '', hash('sha256', $browserNonce))) {
    throw new OAuthStateException('This sign-in was started in another browser.');
}
```

Guarding test: a functional test per provider that calls the callback with a valid state and (a) no
nonce cookie, (b) a nonce cookie from a different `start`, and asserts no `Connection` row is written
and the response is the error redirect.

**Detection**
Today the callback logs a normal successful connect; nothing distinguishes the attack. Proposed: log
the session user (or "none") next to `state.owner_id` at the callback and alert on mismatch; show the
connected external account identity prominently and send the owner a "new connection" notice.

**Related occurrences**
- `McpOAuthController.php:126` + `McpOAuthConsentService.php:154-173`: same root cause.
- `Controller/GoogleAuthController.php:61`, `:103` ("Signed state (no session required)"): same pattern
  on the login flow (login CSRF). Belongs to the authentication reviewer; listed for completeness.

---

### Finding 2: Group directory is visible to every authenticated user, and a share can target a group the actor is not in

- Severity: Low
- Status: Confirmed (listing reproduced; share-to-foreign-group confirmed by code trace only)

**Where**
- `Controller/ShareController.php:253` (`GET /api/v1/iam/subjects`), guard at `:563-568` checks only
  that sharing is enabled.
- `Service/Iam/ShareService.php:573-632` (`searchSubjects`): people are limited to co-members via
  `findCoMemberUserIds`, groups come from `groupRepository->searchByName()` /
  `findAllOrderedByName()` with no membership filter.
- `Repository/GroupRepository.php:62-95`.
- `Service/Iam/ShareService.php:729-744` (`assertSubjectExists`): for a group, checks existence only.
  Called from `grantResolved` at `:116` and from `:175`.

**What**
The subject picker restricts people to the actor's co-members but returns every group on the
instance. The grant path has the same gap: it verifies that the actor may manage the resource
(`:118`) and that the group exists, not that the actor belongs to the group. A user can therefore
learn the names and ids of all groups and push their own items into any group's "shared with us" view.

Where the codebase does it right: the people half of the same method (`findCoMemberUserIds`), and the
group endpoints themselves (`GET /api/v1/groups/{id}/granted-shares` returns 404 for a non-member).

**Preconditions**
Any authenticated user, sharing enabled (`isSharingEnabled`). No flag change needed.

**Reproduction**
```
GET /api/v1/groups/mine                    (as u1)
-> 200 {"groups":[]}

GET /api/v1/iam/subjects                   (as u1)
-> 200 {"personScope":"shared-group","subjects":[{"type":"group","id":10,"name":"Review walk",...}]}

GET /api/v1/groups/10/granted-shares       (as u1)
-> 404 {"error":"You are not a member of this group."}
```
The share-to-group write was deliberately not executed, because group 10 belongs to another reviewer
or to seeded data. Missing step to reproduce it: as u1, `POST` a share of an own resource with
`subjectType=group`, `subjectId=<a group created by me as admin>`; that requires an admin account to
create a group, which I did not use.

**Impact**
Information disclosure of group names (which can reveal customers, teams or projects on a
multi-tenant instance) and unsolicited content injection into any group's shared items, usable for
spam or for planting a prompt/agent that group members may run. No access to the group's data.

**CVSS 3.1**: `AV:N/AC:L/PR:L/UI:N/S:U/C:L/I:L/A:N` = 5.4 (rated Low by impact: names only, no data
access). **CWE-200**, **CWE-285**.

**Fix**
- `searchSubjects`: restrict groups to the actor's memberships unless the actor is an admin or an
  instance policy explicitly opens the directory.
- `assertSubjectExists`: take the actor and require membership (same exception) for
  `SUBJECT_GROUP`; keep the platform-distribution path exempt.

```php
if (!$platformDistribution && !$actor->isAdmin()
    && !$this->groupMembershipRepository->isMember((int) $actor->getId(), $subjectId)) {
    throw new ShareNotAllowedException('You can only share with a group you belong to.');
}
```

Guarding tests: `ShareServiceTest` cases "non-member does not see foreign group in subjects" and
"non-member cannot grant to foreign group".

**Detection**
Share grants are audited, so a foreign-group grant leaves a row, but nothing flags that the actor is
not a member. Proposed: audit field `actor_is_member=false` and an alert on it.

**Related occurrences**
None other found; `assertSubjectExists` is the single choke point for both call sites.

---

### Finding 3: `GET /api/v1/rag/similar/{chunkId}` loads the source chunk without an owner check

- Severity: Low
- Status: Confirmed by code trace (not reproduced)

**Where**
- `Controller/RagController.php:158-200` (`similar`).
- `Service/RAG/VectorSearchService.php:423-428` (`findSimilar`).
- `Service/RAG/VectorStorage/QdrantVectorStorage.php:169-193`: `getDocument((string) $sourceChunkId)`
  with no user filter, then a search filtered by the caller's user id.
- `Service/RAG/VectorStorage/MariaDBVectorStorage.php:176-202`: `ragRepository->find($intChunkId)` with
  no owner check.

**What**
The source chunk is fetched by id alone and its embedding is used as the query vector. Results are
filtered to the caller's own chunks, so no foreign text is returned, but the similarity scores are
computed against another tenant's embedding. Chunk ids are guessable (`doc_{userId}_{fileId}_{chunk}`
on Qdrant, sequential integers on MariaDB).

Where the codebase does it right: `VectorSearchService::passage` (`:511-540`) loads the chunk and calls
`mayReadChunk` before returning anything.

**Preconditions**
Authenticated user with RAG access; knowledge of or guess at a foreign chunk id.

**Reproduction**
Not executed: it needs a victim document that has been embedded, and the local embedding path was
being used by other reviewers. Missing step: u2 uploads and indexes a file, u1 calls
`GET /api/v1/rag/similar/doc_4925_<fileId>_0` and observes 200 with scores instead of 404.

**Impact**
A similarity oracle: by uploading probe texts and reading the scores, an attacker can test hypotheses
about the content of a foreign chunk ("does this chunk talk about X"). Slow, noisy and limited; also
confirms existence of a chunk id.

**CVSS 3.1**: `AV:N/AC:H/PR:L/UI:N/S:U/C:L/I:N/A:N` = 3.1. **CWE-639**.

**Fix**
In `findSimilar`, resolve the chunk through the same `findChunk` + `mayReadChunk` used by `passage`
and return "not found" when the caller may not read it; pass the already-loaded vector to the storage
so the storages no longer fetch by bare id.

Guarding test: `VectorSearchServiceTest::testFindSimilarRejectsForeignChunk` for both storages.

**Detection**
Requests are ordinary 200s today. Proposed: log and count `similar` calls whose chunk id owner differs
from the caller (after the fix these are 404s and can be rate-limited).

**Related occurrences**
Both storage implementations share the root cause; no other caller of `getDocument` by bare id was
found on a user-reachable path.

---

## 3. Ruled out (examined and found sound)

Dynamic checks
- All 130 admin method/route combinations as u1 (level NEW): 403 for every one
  (`SCRATCH/authzB/adminprobe_u1.jar.txt`).
- 39 cross-tenant requests (u1 against u2's memory, widget, MCP server, prompt, agent: read, update,
  delete, and referencing the foreign id from an own object): all 403 / 404 / 400, victim data
  unchanged (`SCRATCH/authzB/xt1.txt`).
- Realtime: u1 subscribing to `user:4925` and `user:2` gets 403, own channel 200.
- `POST /api/v1/setup/admin {}` unauthenticated: 422, setup state reports an admin exists.
- API key creation as level NEW: 403 `API_PLAN_REQUIRED`.

Admin enforcement (no `access_control` rule for `^/api/v1/admin`; enforced per controller)
- Class-level `#[IsGranted('ROLE_ADMIN')]`: AdminCapacity:24, AdminSystemConfig:26, AdminEmbedding:47,
  AdminLogs:24, AdminModelHealth:40, AdminModeration:27, AdminScheduler:24, AdminSearchConfig:22,
  AdminSignupCode:28, AdminSystemInfo:21, AdminUpdates:34, AdminUsage:25, AdminDropboxConnection:23,
  FederationAdmin:20.
- Method-level: AiLiteracy:83/116/130, FreeApi:70/118, VectorStats:90, Setup:221.
- Private `guard()`: AdminGroupController:546-559, AdminAudit:154, AdminConfigLock:144,
  AdminGroupConfig:187, ResearchAdmin:135. Remaining controllers use `isAdmin()` / `requireAdmin`.
- `PromptController` sorting/planning PUT: `isAdmin()`. `ConfigController` global defaults and summary
  model: `isGranted('ROLE_ADMIN')`.
- `AdminImpersonationController::stop` has no ROLE_ADMIN on purpose (it is called while impersonating,
  and is bound to the stash cookie).

Role derivation and self-escalation
- `Entity/User.php:334-359`: ROLE_ADMIN only from `userLevel === 'ADMIN'` or mapped `oidc_roles`.
- `setUserLevel` is called only from `LevelGrantService:399` and guest/anonymous services.
- `oidc_roles` is written only by `OidcUserService:187`.
- `ProfileController.php:234-248`: explicit field allowlist, no level, role or details keys.
- `SignupAdmission.php:53-90`: a plan choice leads to pending payment; a signup code goes through
  `LevelGrantService`.
- `SetupController` first-admin creation: `isSetupRequired` + lock + `countAll()`.
- API Platform: `src/ApiResource` is empty and `api_platform.yaml` has defaults only; no auto-exposed
  resources.
- `IamVoter` exists but no controller uses it; controllers call `AccessGate` / `AgentAccess` directly,
  which is where the decisions are made, so the unused voter is not a gap.

Per-resource ownership
- Plugins `/api/v1/user/{userId}/plugins/*`: every method compares `$user->getId()` to `{userId}`
  (`SynaformController.php:5432-5443`, 49/49 methods; synafastbill 6/6; castingdata 3/3; synamail
  guard x4; synascriber `AdminController:101` `isAdmin`).
- Agents: `Service/Agent/AgentAccess.php` `require`; `AgentService::create` `assertOwnedPrompt` (:85);
  `AgentKnowledgeFolders:37-50` and `AgentRuntimeResolver:118-131` (`ragScopeResolver->canUse`);
  `AgentTriggerMaterializer:217-218`, `:283-284`; `AgentPublishReview:155-245` reviewer checks.
- Widgets: `WidgetController` compares `getOwnerId()` to the user on all owner routes;
  `bindWidgetAgent:1561-1584`; `WidgetSummaryService:479-480` binds summary id to the widget;
  tier gates at `WidgetApiContext:57` and `WidgetController:1168` are server-side.
- Saved tasks: `SavedTaskResumeService:55-83` (approval binding), `SavedTaskService:284-287`
  (`assertUsablePrompt`).
- Custom tools: `CustomToolService:225-232`, `HttpToolExecutor:161` (credential owner).
- Approvals: `ApprovalService:311-319` (`requireOwnedPending`), `:179-190` (`findForViewer`).
- Research: `ResearchRunService:368-379`. Media: `MediaGenerationService:351`.
- Memories: `UserMemoryService` `findForUser` (:179, :210, :276).
- API keys: `ApiKeyController:237-250`, `:280-290`.
- Inbound email handlers: `findByIdAndUser`, including the masked-password path (:274-290); tier gate
  at :166 / :407 (`premiumFeatureGate`) is server-side.
- MCP server configs, MCP OAuth start, MCP tool permissions: `findByIdAndUser`.
- Connections, url-watches, saved prompts, desktop, voice (`owned`), message digests
  (`deactivateOwned`): owner-filtered repository lookups.
- Channels: `ChannelNoticeStore:56-62` `assertBinding`.
- Cloud: `CloudCallerResolver:29-46`, `CloudDeliveryService` `requireOwn`.
- Compute workspaces: `ComputeWorkspaceService:222-243` (`forUser`, `safeRelativePath`).
- WordPress integration: `WordPressIntegrationController:116-122` `assertOwnAccount` plus password
  verification in the service.
- Realtime: `UserChannelAuthorizer`, `WidgetOperatorsAuthorizer`.
- API key scopes: `ApiKeyScopeSubscriber` + `ApiKeyScope` are default-deny for restricted keys, with a
  self-revoke exception and session-only prefixes.
- `/api/test/chat` (public per `security.yaml:148`): unauthenticated POST returns a canned reply from
  the "test" provider, no user data and no real provider call; GET `/api/test/chat` and `/embed`
  return 400.

---

## 4. Hardening ideas

- Add an `access_control` backstop `^/api/v1/admin -> ROLE_ADMIN` (exempting the impersonation exit)
  and a test that iterates the router and asserts every admin route rejects a non-admin. Today one
  forgotten attribute on a new controller is an open admin endpoint.
- Unify the two admin checks. `#[IsGranted('ROLE_ADMIN')]` honours OIDC-mapped roles, `isAdmin()` is
  level-only, so an OIDC admin gets a partial admin surface. Pick one.
- Remove `/api/test/*` from production routing or gate it on the environment.
- Type-validate profile fields (currently allowlisted by name only).
- Prompts and widgets answer 403 for "exists but not yours" and 404 for "does not exist": an existence
  oracle. Return 404 for both.
- Restricted API keys: make sure one scope covers all of `/api/v1/user/{id}/plugins/*` rather than
  relying on per-plugin prefixes.
- WordPress integration `collectPayload` merges query parameters, so a password can arrive in the URL
  and land in access logs; read credentials from the body only.
- Error responses on the local stack include stack traces (dev mode); confirm production does not.

---

## 5. Coverage gaps

- Finding 1 live flow (M365 / Dropbox / MCP OAuth): providers not configured, third-party traffic forbidden.
- Finding 2 write path and Finding 3: not executed, to avoid touching other reviewers' or seeded data
  and shared embedding state.
- Billing, Stripe, in-app purchase: not configured locally.
- Cloud, opendesk and Notion modules: endpoints answer `feature_not_configured`; reviewed statically
  only, and only the owner-resolution layer.
- Synaform sub-resource ids (form, submission, attachment ids below the `{userId}` guard) and
  `PluginAssetController` path handling: the user-id guard was verified on every method, the nested
  lookups were not traced one by one.
- Federation, desktop pairing and voice internal endpoints: guard presence checked, logic not reviewed.
- API key scope behaviour: static review only, because level NEW users cannot mint keys and I did not
  use the seeded accounts to create keys.
- Admin routes exercised as an admin (functional abuse by a legitimate admin, group-admin versus
  instance-admin boundaries inside `AdminGroupController`): not tested dynamically.
- Level-grant and signup-code redemption races: not tested.

Cleanup: all objects created under u2 for the cross-tenant batch (widget, MCP server 40, agent 236,
prompt 450, memory) were deleted. Users 4915 and 4925 remain. A url-watch on the demo account seen
during testing belongs to another reviewer and was left untouched.
