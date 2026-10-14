# Service root map — 73 files, where each one goes and why

Measured on `main`, 2026-10-14. *src / tests* = number of files importing
the class (the size of the `use`-line diff); *yaml* = explicit entries in
`config/services.yaml` that must be renamed in the same PR.

Destination folders marked **(new)** do not exist yet; everything else is
an existing `src/Service/<Folder>/`. Judgment calls are marked ⚖ with the
runner-up in the note; change them in review, not mid-PR.

## Stays — plugin SDK (4)

Imported by external plugin repositories. Moving one is a breaking change
for plugin authors; a `Plugin\Sdk` namespace with `class_alias` shims is V2.

| File | Imported by | src / tests / yaml |
| ---- | ----------- | ------------------ |
| `PluginDataService` (interface) | Synaform, synafastbill, Synamail, synaScriber, in-repo `plugins/castingdata` | 0 / 0 / 0 |
| `ModelConfigService` | Synaform, synafastbill, Synamail | 56 / 106 / 1 |
| `RateLimitService` | Synaform | 47 / 106 / 0 |
| `OidcUserService` | synaScriber | 3 / 3 / 1 |

## C3 — Widget (10)

| File | → | Why | src / tests / yaml |
| ---- | - | --- | ------------------ |
| `WidgetService` | `Widget/` | Widget CRUD | 8 / 11 / 0 |
| `WidgetSessionService` | `Widget/` | Widget visitor sessions | 8 / 11 / 0 |
| `WidgetSetupService` | `Widget/` | AI Setup Assistant for widgets | 3 / 1 / 0 |
| `WidgetSummaryService` | `Widget/` | Session summaries | 1 / 0 / 0 |
| `WidgetExportService` | `Widget/` | Chat export per widget | 1 / 1 / 0 |
| `WidgetRealtimeBroadcaster` | `Widget/` | Realtime fan-out | 1 / 1 / 0 |
| `WidgetEventStoreInterface` | `Widget/` | SSE backing store contract | 1 / 0 / 1 (alias) |
| `DatabaseWidgetEventStore` | `Widget/` | Its Galera-backed implementation | 0 / 1 / 1 (alias target) |
| `HumanTakeoverService` | `Widget/` | Human takeover inside widget chats | 1 / 0 / 0 |
| `WordPressIntegrationService` | `Widget/` | WordPress onboarding wizard embeds a widget | 2 / 1 / 0 |

## C4 — Email, WhatsApp, Notification (10)

| File | → | Why | src / tests / yaml |
| ---- | - | --- | ------------------ |
| `InboundEmailService` | `Email/` | Inbound mail pipeline | 1 / 1 / 1 |
| `InboundEmailHandlerService` | `Email/` | Per-handler routing | 3 / 3 / 0 |
| `MailHandlerLogService` | `Email/` | Handler activity log | 1 / 3 / 0 |
| `EmailWebhookIdempotencyService` | `Email/` | Webhook dedupe | 1 / 3 / 0 |
| `EmailChatService` | `Email/` | Chat over email | 4 / 5 / 0 |
| `InternalEmailService` | `Email/` | Outbound system mail | 13 / 20 / 1 |
| `MailerConfig` | `Email/` | "Can this install send mail?" | 7 / 5 / 0 |
| `WhatsAppService` | `WhatsApp/` | Meta Business API client; `WhatsAppAgentBinding` is already there | 4 / 4 / 1 |
| `DiscordNotificationService` | `Notification/` (new) | Ops alerting webhook (model health, chat errors) — ⚖ not widget-specific, so not `Widget/` | 8 / 19 / 1 |
| `SlackNotificationService` | `Notification/` (new) | Human-handoff webhook — ⚖ sits with Discord as "outbound notifier", runner-up `Widget/` | 2 / 1 / 0 |

## C5 — Auth & identity (13)

| File | → | Why | src / tests / yaml |
| ---- | - | --- | ------------------ |
| `TokenService` | `Auth/` | Access/refresh tokens | 7 / 14 / 0 |
| `JwtValidator` | `Auth/` | OIDC token validation | 0 / 3 / 0 |
| `OidcTokenService` | `Auth/` | Keycloak token exchange (login) | 4 / 3 / 1 |
| `OidcScopeResolver` | `Auth/` | Login scope string | 1 / 1 / 0 |
| `OAuthLoginResponder` | `Auth/` | Final response of a *login* OAuth callback (GitHub/Google/Apple/Keycloak controllers) | 4 / 0 / 1 |
| `NativeAuthHandoffService` | `Auth/` | Mobile login handoff token (`MOBILE-APP SEAM`) | 1 / 1 / 1 |
| `RegistrationConfig` | `Auth/` | Self-registration switch | 9 / 8 / 0 |
| `RecaptchaService` | `Auth/` | Used only by `AuthController` | 1 / 1 / 1 |
| `ImpersonationService` | `Auth/` | Admin acts as another user — ⚖ runner-up `Admin/`; it changes *who is authenticated*, so `Auth/` | 3 / 2 / 0 |
| `OAuthStateService` | `OAuth/` | CSRF state shared by login *and* connection OAuth; `OAuth/` already holds the generic OAuth plumbing | 6 / 2 / 1 |
| `UserLifecycleService` | `Iam/` | Factory for `BUSER` rows | 5 / 2 / 0 |
| `UserDeletionService` | `Iam/` | Account deletion | 2 / 1 / 0 |
| `AccountLanguage` | **`App\Model\`** | Value object used by `Entity\User` and `DTO\RegisterRequest`; moving it out of Service removes two layer violations (§0 #7) | 8 / 0 / 0 |

## C6 — Billing, Usage, IAP (9)

| File | → | Why | src / tests / yaml |
| ---- | - | --- | ------------------ |
| `CostCalculationService` | `Usage/` | Token → cost | 3 / 9 / 0 |
| `CachePriceResolver` | `Usage/` | Cache-read/write rates | 1 / 0 / 0 |
| `UsageStatsService` | `Usage/` | Usage reports | 4 / 12 / 0 |
| `UsageTaximeterConfig` | `Usage/` | In-chat taximeter switch | 5 / 13 / 0 |
| `StorageQuotaService` | `Usage/` | Storage quota — ⚖ runner-up `File/`; it is a *limit*, so `Usage/` | 5 / 8 / 0 |
| `BillingService` | `Billing/` (new) | "Is Stripe configured?" | 8 / 43 / 1 |
| `PremiumFeatureGate` | `Billing/` (new) | Paid-tier decision point | 9 / 28 / 0 |
| `IapPricingService` | `Iap/` | Store pricing map (`MOBILE-APP SEAM`) | 2 / 4 / 1 |
| `MobilePurchaseService` | `Iap/` | Receipt validation (`MOBILE-APP SEAM`) | 6 / 2 / 0 |

## C7 — Chat, Message, Memory, Feedback (16)

| File | → | Why | src / tests / yaml |
| ---- | - | --- | ------------------ |
| `MessageEnqueueService` | `Message/` | Async enqueue — also translate its German docblock | 1 / 3 / 0 |
| `AiResponseSanitizer` | `Message/` | Post-processes model output | 3 / 2 / 0 |
| `ContentModerationService` | `Message/` | Report/moderate messages — ⚖ runner-up `Admin/` | 2 / 0 / 0 |
| `PerfPipelineFlag` | `Message/` | Rollout switch read only by `ChatHandler` / `MediaGenerationHandler` | 2 / 14 / 0 |
| `ConversationSummaryRefreshDispatcher` | `Chat/` | Triggers chat summary refresh | 5 / 14 / 0 |
| `PastedContentText` | `Chat/` | Display helper for pasted content | 3 / 1 / 0 |
| `GuestChatConfig` | `Chat/` | Anonymous trial chat switch — ⚖ runner-up `Auth/` | 6 / 19 / 0 |
| `GuestSessionService` | `Chat/` | Anonymous trial chat sessions — kept next to its switch | 5 / 12 / 1 |
| `UserMemoryService` | `Memory/` | Memory CRUD + search | 16 / 26 / 0 |
| `MemoryExtractionService` | `Memory/` | AI extraction | 5 / 5 / 0 |
| `MemoryExtractionDispatcher` | `Memory/` | Bus dispatch for the above | 2 / 18 / 0 |
| `FeedbackConfigService` | `Feedback/` (new) | Feedback settings | 2 / 14 / 0 |
| `FeedbackConstants` | `Feedback/` (new) | Shared constants | 3 / 1 / 0 |
| `FeedbackContradictionService` | `Feedback/` (new) | Contradiction check | 3 / 3 / 0 |
| `FeedbackExampleService` | `Feedback/` (new) | Example store | 4 / 4 / 0 |
| `UrlContentService` | `Research/` | Fetch + extract a URL — ⚖ used by widget crawl, research and multitask; `Research/` holds the only other web fetcher (`WebResearchService`), runner-up `UrlWatch/` | 9 / 8 / 0 |

## C8 — Media, Speech, Prompt, Security, Infrastructure (11)

| File | → | Why | src / tests / yaml |
| ---- | - | --- | ------------------ |
| `MediaGenerationService` | `Media/` | Image/sound generation | 0 / 1 / 2 (def + alias target) |
| `MediaGenerationServiceInterface` | `Media/` | Its contract | 2 / 2 / 1 (alias) |
| `TtsTextSanitizer` | `Media/` | Prepares text for speech output | 5 / 2 / 0 |
| `WhisperService` | `Stt/` | whisper.cpp transcription | 7 / 18 / 1 |
| `SpeechFailure` | `Stt/` | Why a recording produced no text | 3 / 1 / 0 |
| `WebSpeechConfig` | `Stt/` | Browser Web Speech switch | 1 / 4 / 0 |
| `PromptService` | `Prompt/` | Prompt loading with metadata | 15 / 32 / 0 |
| `EncryptionService` | `Security/` | At-rest encryption; `SsrfGuard` is already there | 18 / 22 / 1 |
| `CircuitBreaker` | `Infrastructure/` | Generic resilience pattern; `RedisService` is already there | 2 / 9 / 1 |
| `PerfTimer` | `Infrastructure/` | Per-request timer — ⚖ runner-up top-level `App\Observability\`; kept inside Service to avoid a cross-namespace move in V1 | 5 / 2 / 0 |
| `MonologFormatterFactory` | **`App\Monolog\`** | Top-level `Monolog/` already holds the other two Monolog classes; referenced as a `factory:` in `services.yaml` | 0 / 0 / 2 |

## Totals

| | Files |
| - | ----- |
| Stays (plugin SDK) | 4 |
| C3 | 10 |
| C4 | 10 |
| C5 | 13 |
| C6 | 9 |
| C7 | 16 |
| C8 | 11 |
| **Total** | **73** |

New folders: `Billing/`, `Feedback/`, `Notification/`. Cross-namespace
moves (out of `App\Service\`): `AccountLanguage` → `App\Model\`,
`MonologFormatterFactory` → `App\Monolog\`.
