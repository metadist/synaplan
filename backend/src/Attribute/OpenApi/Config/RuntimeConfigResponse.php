<?php

declare(strict_types=1);

namespace App\Attribute\OpenApi\Config;

use OpenApi\Attributes as OA;

/**
 * The 200 response of GET /api/v1/config/runtime, kept out of the controller
 * because the payload documents some sixty keys.
 *
 * swagger-php reads attributes by `instanceof`, so this subclass renders the
 * same OpenAPI document as the inline attribute did. The generated frontend
 * schemas pin a hash of that document: a change here is an API change.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class RuntimeConfigResponse extends OA\Response
{
    public function __construct()
    {
        parent::__construct(
            response: 200,
            description: 'Public runtime configuration',
            content: new OA\JsonContent(
                properties: [
                    ...self::accessAndFeatureProperties(),
                    ...self::brandingAndSpeechProperties(),
                    ...self::integrationProperties(),
                    ...self::clientProperties(),
                    ...self::chatProperties(),
                    ...self::setupProperties(),
                ]
            ),
        );
    }

    /**
     * Billing, sign-in surfaces, reCAPTCHA and the feature flags.
     *
     * @return list<OA\Property>
     */
    private static function accessAndFeatureProperties(): array
    {
        return [
            new OA\Property(
                property: 'billing',
                type: 'object',
                description: 'Billing/subscription status (false for open-source deployments)',
                properties: [
                    new OA\Property(property: 'enabled', type: 'boolean', example: false),
                ]
            ),
            new OA\Property(
                property: 'auth',
                type: 'object',
                description: 'Authentication surface flags. Lets the frontend hide sign-up affordances when the operator runs an SSO-/OIDC-only instance.',
                properties: [
                    new OA\Property(property: 'registrationEnabled', type: 'boolean', example: true, description: 'When false, local email/password self-registration is disabled (set REGISTRATION_ENABLED=false, e.g. for OIDC-only deployments). The /register endpoint is also refused server-side.'),
                    new OA\Property(property: 'guestChatEnabled', type: 'boolean', example: true, description: 'When false, the anonymous guest trial chat is disabled (set GUEST_CHAT_ENABLED=false, e.g. for OIDC-only deployments): the frontend sends unauthenticated visitors to /login and every /api/v1/guest endpoint is refused server-side.'),
                    new OA\Property(property: 'mailerConfigured', type: 'boolean', example: true, description: 'False when MAILER_DSN is unset or the null transport. The forgot-password page then shows the CLI reset instead of pretending an email will arrive.'),
                ]
            ),
            new OA\Property(
                property: 'recaptcha',
                type: 'object',
                description: 'reCAPTCHA v3 human check. `enabled` is false whenever Turnstile is configured, so at most one provider is active.',
                properties: [
                    new OA\Property(property: 'enabled', type: 'boolean', example: true),
                    new OA\Property(property: 'siteKey', type: 'string', example: '6LcXXXXXXXXXXXXXXXXXXXXXXXXXXXXX'),
                ]
            ),
            new OA\Property(
                property: 'turnstile',
                type: 'object',
                description: 'Cloudflare Turnstile human check (TURNSTILE_SITE_KEY + TURNSTILE_SECRET_KEY). Takes precedence over reCAPTCHA. The browser obtains a token before sign-up, sign-in and every OAuth start.',
                properties: [
                    new OA\Property(property: 'enabled', type: 'boolean', example: false),
                    new OA\Property(property: 'siteKey', type: 'string', example: '0x4AAAAAAAxxxxxxxxxxxxxxxx'),
                ]
            ),
            new OA\Property(
                property: 'features',
                type: 'object',
                properties: [
                    new OA\Property(property: 'help', type: 'boolean', example: true, description: 'Enable help system'),
                    new OA\Property(property: 'memoryService', type: 'boolean', example: true, description: 'Qdrant vector database availability'),
                    new OA\Property(property: 'savedTasks', type: 'boolean', example: false, description: 'When true, AI Instructions shows Saved Task chrome. Widget chat never runs Saved Tasks.'),
                    new OA\Property(property: 'selfAware', type: 'boolean', example: true, description: 'When true, the web chat can answer what this installation can do and cite official documentation. Off restores the previous chat behaviour.'),
                    new OA\Property(property: 'desktopAgentEnabled', type: 'boolean', example: false, description: 'When true, the Synaplan Desktop pairing surface (Channels → Synaplan Desktop) and desktop job APIs are exposed. On by default; pin with FEATURE_DESKTOP_AGENT_ENABLED or the Admin Features tab.'),
                    new OA\Property(property: 'platformLinksEnabled', type: 'boolean', example: false, description: 'When true, partner instances can register and exchange a link code for a per-user API key. On by default; pin with FEATURE_PLATFORM_LINKS_ENABLED. The Outlook add-in connect path is not gated by this flag.'),
                    new OA\Property(property: 'agentsEnabled', type: 'boolean', example: false, description: 'When true, the Assistants CRUD API and stream agentId pin are available. On by default; pin with FEATURE_AGENTS_ENABLED.'),
                    new OA\Property(property: 'bundleEnabled', type: 'boolean', example: false, description: 'When true, Settings shows Export & import. On by default; pin with FEATURE_BUNDLE_ENABLED.'),
                    new OA\Property(property: 'iamGroups', type: 'boolean', example: false, description: 'When true, Operate shows Users and the group API is available. On by default; pin with FEATURE_IAM_GROUPS_ENABLED.'),
                    new OA\Property(property: 'iamSharing', type: 'boolean', example: false, description: 'When true, owners can share a knowledge folder, chat, AI assistant, saved task or chat widget with a user, a group or everyone. Requires iamGroups. On by default; pin with FEATURE_IAM_SHARING_ENABLED.'),
                    new OA\Property(property: 'iamImpersonationDisabled', type: 'boolean', example: false, description: 'When true, administrators cannot start an impersonation session (IAM.ADMIN_IMPERSONATION=disabled).'),
                    new OA\Property(property: 'iamPolicies', type: 'boolean', example: false, description: 'When true, Users shows Policies and group defaults / allowed models apply. Requires iamGroups. On by default; pin with FEATURE_IAM_GROUP_POLICIES_ENABLED.'),
                    new OA\Property(property: 'officeConvertEnabled', type: 'boolean', example: false, description: 'When true, Collabora CODE convert-to is configured (OFFICE_CONVERT_URL). Office thumbnails, PDF export, inline preview and combine stay off while this is false.'),
                    new OA\Property(property: 'computeEnabled', type: 'boolean', example: false, description: 'When true, file work is on (sidecar URL + token and COMPUTE.ENABLED). The API-key create form then offers the compute:run grant.'),
                    new OA\Property(property: 'computeWorkspacesEnabled', type: 'boolean', example: false, description: 'When true, file-work runs may keep a folder between runs and Files shows Workspace. Off by default; needs computeEnabled.'),
                    new OA\Property(property: 'documentToolsEnabled', type: 'boolean', example: false, description: 'When true, structured office editing (document tools, version history, combine as DOCX/XLSX/PPTX) is available. On by default; pin with FEATURE_DOCUMENT_TOOLS_ENABLED.'),
                    new OA\Property(property: 'toolsRegistryEnabled', type: 'boolean', example: true, description: 'When true, GET /api/v1/tools lists the tool registry. Kill switch after the Wave 4 registry refactor.'),
                    new OA\Property(property: 'toolsApprovalsEnabled', type: 'boolean', example: false, description: 'When true, write-class tools ask for approval and Manage → Automations → Approvals is shown. On by default; pin with FEATURE_TOOLS_APPROVALS_ENABLED.'),
                    new OA\Property(property: 'toolsCustomHttpEnabled', type: 'boolean', example: false, description: 'When true, Connections shows Custom tools for HTTP/OpenAPI tools. On by default; pin with FEATURE_TOOLS_CUSTOM_HTTP_ENABLED.'),
                    new OA\Property(property: 'workflowsBuilderEnabled', type: 'boolean', example: false, description: 'When true, Saved Tasks show a Steps editor and can start from another system. On by default; pin with FEATURE_WORKFLOWS_BUILDER_ENABLED.'),
                    new OA\Property(property: 'smartSearchAi', type: 'boolean', example: true, description: 'When true, the search palette offers AI help (POST /api/v1/search/interpret): FEATURE_SEARCH_AI_ENABLED is on and the search model (the chat model of the person unless an admin pins one) can answer. False for anonymous clients.'),
                ]
            ),
        ];
    }

    /**
     * White-label branding, live voice and speech-to-text.
     *
     * @return list<OA\Property>
     */
    private static function brandingAndSpeechProperties(): array
    {
        return [
            new OA\Property(
                property: 'branding',
                type: 'object',
                description: 'White-label branding (Epic 4). Defaults reproduce the historical Synaplan look. Public — no auth required.',
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Synaplan', description: 'Displayed brand/product name'),
                    new OA\Property(property: 'tagline', type: 'string', example: '', description: 'Optional short brand description/tagline'),
                    new OA\Property(property: 'primaryColor', type: 'string', example: '#003fc7', description: 'Accent color injected into the --brand CSS variables at runtime'),
                    new OA\Property(property: 'secondaryColor', type: 'string', example: '', description: 'Optional secondary color; empty string keeps the default palette'),
                    new OA\Property(property: 'accentColor', type: 'string', example: '', description: 'Optional accent color; empty string keeps the default palette'),
                    new OA\Property(property: 'primaryColorDark', type: 'string', example: '', description: 'Dark-mode primary color; empty string auto-derives a dark tint from primaryColor'),
                    new OA\Property(property: 'secondaryColorDark', type: 'string', example: '', description: 'Dark-mode secondary color; empty string reuses secondaryColor'),
                    new OA\Property(property: 'accentColorDark', type: 'string', example: '', description: 'Dark-mode accent color; empty string reuses accentColor'),
                    new OA\Property(property: 'fontFamily', type: 'string', example: '', description: 'Body font-family stack; empty string keeps the default font'),
                    new OA\Property(property: 'headingFontFamily', type: 'string', example: '', description: 'Heading font-family stack; empty string falls back to fontFamily/default'),
                    new OA\Property(property: 'fontUrl', type: 'string', example: '', description: 'Optional web-font stylesheet URL; must be CSP-allowed. Empty string = no external font'),
                    new OA\Property(property: 'logoUrl', type: 'string', example: '', description: 'Light-mode logo URL; empty string falls back to the bundled asset'),
                    new OA\Property(property: 'logoDarkUrl', type: 'string', example: '', description: 'Dark-mode logo URL; empty string falls back to the bundled asset'),
                    new OA\Property(property: 'iconUrl', type: 'string', example: '', description: 'Brand icon/favicon URL; empty string falls back to the bundled asset'),
                    new OA\Property(property: 'resolvedLogoUrl', type: 'string', example: '/synaplan-dark.svg', description: 'Logo for a light theme, never empty: logoUrl, else the bundled logo. The resolved* fields are for integrations that cannot apply the fallbacks themselves'),
                    new OA\Property(property: 'resolvedLogoDarkUrl', type: 'string', example: '/synaplan-light.svg', description: 'Logo for a dark theme, never empty: logoDarkUrl, else logoUrl, else the bundled logo'),
                    new OA\Property(property: 'resolvedIconUrl', type: 'string', example: '/single_bird-dark.svg', description: 'Icon for a light theme, never empty: iconUrl, else the resolved logo, else the bundled icon'),
                    new OA\Property(property: 'resolvedIconDarkUrl', type: 'string', example: '/single_bird-light.svg', description: 'Icon for a dark theme, never empty: iconUrl, else the resolved dark logo, else the bundled icon'),
                    new OA\Property(property: 'homepageUrl', type: 'string', example: 'https://www.synaplan.com', description: 'Brand homepage link used in auth/footer surfaces'),
                    new OA\Property(property: 'privacyUrl', type: 'string', example: 'https://www.synaplan.com/privacy-policy', description: 'Privacy-policy link (reachable in-app + store metadata; store-policy mandatory)'),
                    new OA\Property(property: 'termsUrl', type: 'string', example: 'https://www.synaplan.com/terms', description: 'Terms-of-use link (reachable in-app + store metadata)'),
                    new OA\Property(property: 'accountDeletionUrl', type: 'string', example: '', description: 'Account-deletion link (Google Play store policy). Empty string lets the app fall back to its own public /account-deletion page'),
                    new OA\Property(property: 'landingPage', type: 'string', example: '', description: 'Logged-out landing: route name or free-form path (starts with "/"); empty string keeps the default landing'),
                    new OA\Property(property: 'defaultRoute', type: 'string', example: '', description: 'Post-login default: route name or free-form path (starts with "/"); empty string keeps the default route'),
                    new OA\Property(property: 'showPoweredBy', type: 'boolean', example: true, description: 'Whether to show the "· powered by <label>" attribution'),
                    new OA\Property(property: 'poweredByLabel', type: 'string', example: 'Synaplan', description: 'Attribution label (the platform being credited)'),
                    new OA\Property(property: 'poweredByUrl', type: 'string', example: 'https://www.synaplan.com', description: 'Attribution link target'),
                ]
            ),
            new OA\Property(
                property: 'voiceLive',
                type: 'object',
                required: ['enabled'],
                description: 'Live voice. enabled is true only when the gateway is configured and a speech-to-speech model has a provider key. Older clients ignore this key.',
                properties: [
                    new OA\Property(property: 'enabled', type: 'boolean', example: false),
                ]
            ),
            new OA\Property(
                property: 'speech',
                type: 'object',
                description: 'Speech-to-text configuration',
                properties: [
                    new OA\Property(
                        property: 'whisperEnabled',
                        type: 'boolean',
                        example: true,
                        description: 'When true, local Whisper.cpp is available for record-then-transcribe mode.'
                    ),
                    new OA\Property(
                        property: 'webSpeechEnabled',
                        type: 'boolean',
                        example: true,
                        description: 'When false, the frontend never uses the browser\'s cloud-backed Web Speech API for speech-to-text (set WEB_SPEECH_ENABLED=false on air-gapped instances) and records for the server-side transcription path instead, or hides the microphone when speechToTextAvailable is false too.'
                    ),
                    new OA\Property(
                        property: 'speechToTextAvailable',
                        type: 'boolean',
                        example: true,
                        description: 'When true, any speech-to-text method is available (local Whisper OR API models like Groq/OpenAI). Frontend should show microphone button.'
                    ),
                ]
            ),
        ];
    }

    /**
     * Plugins, tag manager, build line and the realtime gateway.
     *
     * @return list<OA\Property>
     */
    private static function integrationProperties(): array
    {
        return [
            new OA\Property(
                property: 'plugins',
                type: 'array',
                description: 'List of installed plugins for the current user',
                items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'hello_world'),
                        new OA\Property(property: 'version', type: 'string', example: '1.0.0'),
                        new OA\Property(property: 'description', type: 'string', example: 'A simple hello world plugin'),
                        new OA\Property(property: 'capabilities', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(
                            property: 'chatCommands',
                            type: 'array',
                            description: 'Slash-commands this plugin registers in the chat composer',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'command', type: 'string', example: 'fastbill'),
                                    new OA\Property(property: 'endpoint', type: 'string', example: '/chat'),
                                    new OA\Property(property: 'description', type: 'string', example: 'Talk to your FastBill account'),
                                ],
                                type: 'object'
                            )
                        ),
                    ]
                )
            ),
            new OA\Property(
                property: 'googleTag',
                type: 'object',
                description: 'Google Tag Manager / Google Analytics configuration',
                properties: [
                    new OA\Property(
                        property: 'enabled',
                        type: 'boolean',
                        example: true,
                        description: 'Whether Google Tag tracking is enabled'
                    ),
                    new OA\Property(
                        property: 'tagId',
                        type: 'string',
                        example: 'G-XXXXXXXXXX',
                        description: 'Google Tag ID (GTM-XXXXXXX or G-XXXXXXXXXX)'
                    ),
                ]
            ),
            new OA\Property(
                property: 'build',
                type: 'object',
                description: 'Build and deployment information for debugging',
                properties: [
                    new OA\Property(
                        property: 'version',
                        type: 'string',
                        example: '2.7.0',
                        description: 'Application version'
                    ),
                    new OA\Property(
                        property: 'ip',
                        type: 'string',
                        example: '10.0.0.2',
                        description: 'Internal server IP (not public)'
                    ),
                ]
            ),
            new OA\Property(
                property: 'realtime',
                type: 'object',
                description: 'Realtime / WebSocket gateway settings consumed by the frontend Centrifugo client. There is no transport fallback — when `enabled` is false the dashboard simply does not subscribe.',
                properties: [
                    new OA\Property(
                        property: 'enabled',
                        type: 'boolean',
                        example: true,
                        description: 'Master kill-switch. When false, the dashboard skips every realtime subscription.'
                    ),
                    new OA\Property(
                        property: 'wsUrl',
                        type: 'string',
                        example: 'wss://app.example.com/connection/websocket',
                        description: 'Browser-facing WebSocket endpoint. Empty string means "use same-origin /connection/websocket" (Caddy reverse-proxies to centrifugo).'
                    ),
                ]
            ),
        ];
    }

    /**
     * The calling client and the mobile update gate.
     *
     * @return list<OA\Property>
     */
    private static function clientProperties(): array
    {
        return [
            new OA\Property(
                property: 'client',
                type: 'object',
                description: 'Server-confirmed identity of the calling client, derived from the User-Agent. Lets the frontend switch behaviour server-truthfully (e.g. payment channel gating) instead of trusting only the client-side Capacitor.isNativePlatform() flag. Identity hint only — never an auth control.',
                properties: [
                    new OA\Property(
                        property: 'isMobileApp',
                        type: 'boolean',
                        example: false,
                        description: 'True when the request carries the official "Synaplan Mobile Vx.x" User-Agent token.'
                    ),
                    new OA\Property(
                        property: 'appVersion',
                        type: 'string',
                        nullable: true,
                        example: '4.0',
                        description: 'Parsed app version (major.minor[.patch]) from the User-Agent, or null for web clients.'
                    ),
                    new OA\Property(
                        property: 'platform',
                        type: 'string',
                        enum: ['web', 'mobile'],
                        example: 'web',
                        description: 'Resolved client platform.'
                    ),
                ]
            ),
            new OA\Property(
                property: 'mobile',
                type: 'object',
                description: 'Forced-update gate (Epic 8.2). The operator configures a minimum supported app version; the server compares it against the parsed UA version. Empty minVersion means no gate.',
                properties: [
                    new OA\Property(
                        property: 'minVersion',
                        type: 'string',
                        example: '4.0',
                        description: 'Minimum supported app version, or empty string when no gate is configured.'
                    ),
                    new OA\Property(
                        property: 'updateRequired',
                        type: 'boolean',
                        example: false,
                        description: 'True when the calling mobile app is older than minVersion and must show a blocking "please update" screen.'
                    ),
                    new OA\Property(
                        property: 'updateEnforceAfter',
                        type: 'string',
                        example: '2026-07-17T12:00:00Z',
                        description: 'ISO-8601 grace-period deadline. Before this timestamp the update is not blocking; empty means immediate enforcement.'
                    ),
                    new OA\Property(
                        property: 'iosAppUrl',
                        type: 'string',
                        example: 'https://apps.apple.com/app/id000000000',
                        description: 'App Store link for the update button (empty when unset).'
                    ),
                    new OA\Property(
                        property: 'androidAppUrl',
                        type: 'string',
                        example: 'https://play.google.com/store/apps/details?id=com.synaplan.app',
                        description: 'Play Store link for the update button (empty when unset).'
                    ),
                ]
            ),
        ];
    }

    /**
     * Chat chrome, usage display and the named AI providers.
     *
     * @return list<OA\Property>
     */
    private static function chatProperties(): array
    {
        return [
            new OA\Property(
                property: 'marketingNews',
                type: 'object',
                description: 'Guest-landing marketing news master switch. When false, the frontend renders no news section and performs no news fetch.',
                properties: [
                    new OA\Property(
                        property: 'enabled',
                        type: 'boolean',
                        example: false,
                        description: 'Admin-controlled master switch (off by default). Anonymous visitors only.'
                    ),
                ]
            ),
            new OA\Property(
                property: 'chatWelcome',
                type: 'object',
                description: 'Empty-chat promotions. Missing configuration keeps both on.',
                properties: [
                    new OA\Property(property: 'showStoreCards', type: 'boolean', example: true),
                    new OA\Property(property: 'showWidgetPromo', type: 'boolean', example: true),
                ]
            ),
            new OA\Property(
                property: 'chatActions',
                type: 'object',
                description: 'Whether this account may export or share chats. Missing policy keeps both on.',
                properties: [
                    new OA\Property(property: 'export', type: 'boolean', example: true),
                    new OA\Property(property: 'share', type: 'boolean', example: true),
                ]
            ),
            new OA\Property(
                property: 'usageTaximeter',
                type: 'object',
                description: 'In-chat usage-display master switch (admin-controlled, on by default). When false, the frontend renders no consumption bar/ring and no per-message token-cost badge, and performs no usage-summary fetch. Does not affect the Statistics page.',
                properties: [
                    new OA\Property(
                        property: 'enabled',
                        type: 'boolean',
                        example: true,
                        description: 'Whether the in-chat usage display is enabled platform-wide (default true).'
                    ),
                ]
            ),
            new OA\Property(
                property: 'progressNarration',
                type: 'object',
                description: 'Admin-controlled switches for how much the chat narrates while an answer is prepared (all default true). Affects the web chat display only; the SSE stream always carries the full metadata.',
                properties: [
                    new OA\Property(property: 'steps', type: 'boolean', example: true, description: 'Show the ordered step list with finished phases (false: only the current phase).'),
                    new OA\Property(property: 'models', type: 'boolean', example: true, description: 'Name the model and provider doing the work (false: generic wording).'),
                    new OA\Property(property: 'timings', type: 'boolean', example: true, description: 'Show step durations and the live elapsed counter.'),
                ]
            ),
            new OA\Property(
                property: 'aiProviders',
                type: 'array',
                description: 'Display names of the AI providers a user\'s input can reach on this instance, for the disclosure App Store Review Guideline 5.1.2(i) requires. Empty when none are configured.',
                items: new OA\Items(type: 'string', example: 'Anthropic')
            ),
        ];
    }

    /**
     * Provider health, the setup wizard and the feature modules.
     *
     * @return list<OA\Property>
     */
    private static function setupProperties(): array
    {
        return [
            new OA\Property(
                property: 'unavailableProviders',
                type: 'array',
                description: 'AI providers that are disabled due to missing API keys (only for authenticated users)',
                items: new OA\Items(type: 'string', example: 'Anthropic'),
                nullable: true
            ),
            new OA\Property(
                property: 'setup',
                type: 'object',
                description: 'First-run setup status. wizardRequired, wizardEnabled and demoLoginHint are public so the SPA can route a virgin install into the setup wizard; chatReady is only set for authenticated users.',
                nullable: true,
                properties: [
                    new OA\Property(
                        property: 'wizardRequired',
                        type: 'boolean',
                        example: false,
                        description: 'True only on a virgin installation that still needs its first administrator: no BCONFIG SETUP.COMPLETED flag, not a single BUSER row, and SETUP_WIZARD_ENABLED not disabled. The SPA then redirects every route to the setup wizard, and the rest of the API answers 503 SETUP_REQUIRED. False on every existing installation.'
                    ),
                    new OA\Property(
                        property: 'wizardEnabled',
                        type: 'boolean',
                        example: true,
                        description: 'False only when the operator set SETUP_WIZARD_ENABLED=false. The wizard then never applies on this installation, no matter how empty it is — the intended setup for SSO/OIDC deployments where the administrator arrives through IdP roles and no local account is ever created. The SPA uses this to stop re-checking the setup state at all. True by default.'
                    ),
                    new OA\Property(
                        property: 'chatReady',
                        type: 'boolean',
                        example: true,
                        description: 'True when a real AI provider (cloud key or a pulled local Ollama model) can serve the requesting user\'s effective default chat model. The built-in TestProvider demo responder does not count. Omitted for anonymous clients.'
                    ),
                    new OA\Property(
                        property: 'demoLoginHint',
                        type: 'boolean',
                        example: false,
                        description: 'True only on a fresh dev/test install whose seeded admin@synaplan.com password is still the fixture default. The login page may then show that account. Always false in production.'
                    ),
                ]
            ),
            new OA\Property(
                property: 'modules',
                type: 'object',
                description: 'Declared feature modules keyed by module id (tika, docling, office_convert, searxng, piper_tts, local_ai, higgsfield, google_ai, thehive, stripe_billing, mobile_iap, whatsapp, telegram, compute). `configured` is whether the installation provides the module; `gated` is whether an absent module answers 404 feature_not_configured on its routes (MODULES.GATE_<ID>). New installs seed every gate on; existing BCONFIG rows are never overwritten. Older clients ignore this key; a client that does not receive it treats every module as configured.',
                additionalProperties: new OA\AdditionalProperties(
                    type: 'object',
                    required: ['configured', 'gated'],
                    properties: [
                        new OA\Property(property: 'configured', type: 'boolean', example: true),
                        new OA\Property(property: 'gated', type: 'boolean', example: false),
                    ]
                )
            ),
        ];
    }
}
