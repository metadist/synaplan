# Reviewer notes: infra, supply chain, crypto/secrets

Commit a2e57b08d. No secret values here. Saved by the lead from the reviewer's hand-back.

## F1 High: WhatsApp webhook unauthenticated (POST by code trace; GET confirmed live)

- security.yaml:250 PUBLIC_ACCESS; `backend/src/Controller/WebhookController.php:150-245` reads no header.
- `Service/WhatsApp/WhatsAppService.php:661-669` verifyWebhookSignature() has no caller (and keys the
  HMAC with the verify token, not Meta's app secret). No env var for the app secret exists.
- `Service/Email/EmailChatService.php:125-155` findOrCreateUserFromPhone (orders by latest verification, :134);
  `Service/WhatsApp/Turn/WhatsAppIntake.php:57-105` admit, :254-315 handleVerificationCode (:309-311 sets
  phone_verified_at), :73 effectiveUserId; `PhoneVerificationController.php:179-181`.
- GET verify `WebhookController.php:117-138`: `$token === $this->whatsappWebhookVerifyToken`.
- Only guard: `Module/Http/ModuleGateListener.php:53` (404 feature_not_configured);
  `Module/Channel/WhatsappModule.php` isConfigured = enabled && accessToken != ''.
- Done right: Stripe (`StripeWebhookController.php:94`), Telegram (`TelegramWebhookAcceptor.php:95`),
  saved tasks (`SavedTaskWebhookIngress.php:57-62`), voice (`VoiceLiveInternalAuth.php:26-44`).
Live: `GET /api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=&hub_challenge=audit-echo` -> 200 `audit-echo`.
Live: POST -> 404 `{"error":"feature_not_configured","module":"whatsapp"}` (gate only).
Preconditions: WHATSAPP_ENABLED=true + WHATSAPP_ACCESS_TOKEN (docs/WHATSAPP.md:34-36).
Impact: act as any phone-verified user; phone-number takeover via forged verification-code message;
operator number messages arbitrary recipients. CVSS AV:N/AC:L/PR:N/UI:N/S:U/C:L/I:H/A:L = 8.6. CWE-345, CWE-306.
Fix: WHATSAPP_APP_SECRET; hash_equals('sha256='.hash_hmac('sha256', body, appSecret), X-Hub-Signature-256);
isConfigured() requires it; GET refuses empty token, hash_equals; integration tests.
Missing to reproduce: enable the module with a dummy token, send unsigned POST.

## F2 Medium (High on a routable host), Confirmed: install.sh try-mode exposes dev stack on 0.0.0.0

`install.sh:8-10,188-213`; `docker-compose.yml:209,308,944,1097,1234,1553` + mailhog (5173, 8000, 3307,
9999, 8082, 6333, 1025/8025; Keycloak 8080/8443, Ollama 11435 with profiles); :1229-1232 phpMyAdmin
PMA_USER/PMA_PASSWORD auto-login; :349 static DB creds; :1305-1306 Keycloak admin/admin; :1018-1022
Centrifugo changeme_* and origins *; :330 APP_ENV=dev. Done right: 127.0.0.1 binds :1366,1404,1494,1532;
`deploy/compose.yaml:467`. Live: `curl localhost:8082/` -> 200 phpMyAdmin no login; `curl localhost:6333/collections` -> 200.
CVSS AV:A/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H = 8.8. CWE-1188, CWE-306, CWE-798.
Fix: `${SYNAPLAN_BIND:-127.0.0.1}:` on every published port in dev files; warn on public address; drop
PMA auto-login; compose test like tests/coolify-compose.test.mjs.
Related: `docker-compose-minimal.yml:174,243,574,594,615`; `backend/.env.example:16,90,94,101,110-111`.

## F3 Medium (High if not rotated): credentials in public git history (validity not tested)

- `d96dcb2e067c` (2025-06-29) `web/inc/_mail.php`: AWS SES SMTP credential pair (AKIA id + SMTP password). Removed in b2b253200.
- `77d3cc79ccd5` (2025-09-16) `app/inc/_frontend.php`: Cloudflare Turnstile secret. Removed in dd06a0b27.
- `89c26a6042a0` `devtests/doc_googlefiles.html`: Google API key in a saved docs page (likely that page's public key).
Test-only values not findings. CVSS 6.5. CWE-540/798. Fix: rotate both; add secret scanning/push protection.

## F4 Medium, Confirmed (code): bearer credentials stored in plaintext

API keys: `ApiKeyController.php:198`, `ApiKeyRepository.php:28`. Refresh (30 d), reset, verification
tokens: `TokenRepository.php:81`, `:25`; callers `AuthController.php:249,925`, `TokenService.php:142`,
`VerificationResendService.php:68`, `AdminPeopleAccountService.php:109`, `PaidAccountCreator.php:131`.
Done right: `VerificationTokenRepository.php:55-70` createHashedToken, `CodeSignup.php:82-83`.
CVSS AV:N/AC:H/PR:H/UI:N/S:U/C:H/I:H/A:N = 5.9. CWE-256/522. Fix: store SHA-256, look up by hash, prefix column.

## F5 Low (latent): APP_SECRET signs three token families, same format; no empty/placeholder guard

`Service/Auth/TokenService.php:409-415,455-458`; `Service/OAuth/OAuthStateService.php:101-108,146-149`
(generateState merges caller data :45-49); `Service/Auth/NativeAuthHandoffService.php:57,80`.
Done right: `MediaAccessTokenService.php:149`, `WopiTokenService.php:89`, `TokenCipher.php:21`;
guard `Realtime/Token/RealtimeTokenService.php:101-106`. All generateState callers checked: fixed keys,
disjoint claim names -> no forgery today. CVSS 4.8. CWE-323. Fix: HKDF per purpose; boot check in prod.

## F6 Low: EncryptionService is AES-256-CBC without MAC, one key, no row binding

`Service/Security/EncryptionService.php:15-24,36-50,78-90`. Consumers: ProviderKeyStore, PlugKeyStore,
UserProviderKeyResolver, OpenAiCompatibleEndpointRegistry, AesCredentialVault, McpServerConfig,
InboundEmailHandler, AccessTokenKeyStore, FederationIdentityStore, NotifyKeyStore, Microsoft/Dropbox
OAuthConfig, FreeApiState, SystemConfigWriter. All decrypt inputs from DB (no remote oracle).
CVSS 3.3. CWE-353/327. Fix: v2 XChaCha20-Poly1305 with HKDF key and AAD group|setting|ownerId.

## Ruled out

Dependencies (composer audit --locked 0; npm audit --omit=dev 0 in frontend, cloudflare, root);
workflows (no pull_request_target, SHA-pinned actions, contents: read default, publish gated
`ci.yml:506-515`, workflow_run checks `aws-ami.yml:75-80,184-187`, `release-rollout.yml:61-64`, no
untrusted interpolation, self-hosted runner schedule/dispatch only `compute-nightly.yml:57`);
production secrets (`deploy/scripts/secrets-init.sh:71-98,204-216`, `install.sh:284`); production
exposure (`deploy/compose.yaml:467`, internal network, digest pins, APP_ENV=prod :53-54, sandbox
network none :772,782); first admin (503 SETUP_REQUIRED until wizard, `install.sh:289-292`); AWS
(IMDSv2, encrypted EBS); JWT (`JwtValidator.php:41-48`, `AccessTokenValidator.php:50`,
`ClientKeys.php:72-90`, `ClientAssertionVerifier.php:68-78`, `NotificationToken.php:111`,
`VoiceLiveTicketService.php:50`); realtime secret guard; media/WOPI/attach/relay/voice tokens; native
handoff (120 s, single-use `NativeAuthHandoffService.php:88-118`); Stripe/Telegram/saved-task webhooks;
randomness (random_bytes for all secrets); password hashing auto; key masking
(`ProviderKeyStore.php:259-267`, `SecretValueGuard.php:81`); sidecar constant-time compares; no
profiler bundle; notify key rotation (`NotifyKeyStore.php:69-88`).

## Hardening

1. /api/test/* public in prod image (`TestMessageController.php:14`). 2. /api/health lists configured
providers and error text. 3. `?api_key=` accepted (`ApiKeyAuthenticator.php:51,83`); media_token in
access logs. 4. Redis/Qdrant/Tika unauthenticated inside the internal network. 5. prod Redis
allkeys-lru also holds nonces/PKCE/rate-limit state. 6. compute sidecar root + Docker socket
(`deploy/compose.yaml:790,808`, `docker-compose.yml:1457,1471`), public dev default token (:1466).
7. coolify/quickstart images by tag only. 8. `visual-baselines.yml:42-44` ref_name in shell
(dispatch-only). 9. main log channels not scrubbed (`monolog.yaml:36,51`); `WebhookController.php:239`
reads $_ENV APP_ENV. 10. stores swallow decrypt errors and regenerate keys. 11. secret scanning.

## Coverage gaps

F1 POST end to end; where try-mode gets APP_SECRET (`_docker/backend/docker-entrypoint.sh:178-181`);
F3 validity; govulncheck not installed (Go sidecars unaudited); no image scan; casaos/umbrel/elestio/
aws scripts only grepped; exception messages sampled; history scan pattern-based.
