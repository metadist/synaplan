/**
 * Application Configuration
 *
 * Centralized configuration management.
 * Configuration is loaded from backend API at runtime (no build-time env vars needed).
 *
 * This is a simple wrapper around httpClient config functions to avoid circular dependency.
 */

import {
  getConfig,
  getConfigSync,
  getApiBaseUrl,
  reloadConfig,
  getUnavailableProviders,
} from '@/services/api/httpClient'
import { isNativeApp, getNativeApiBaseUrl } from '@/services/api/nativeRuntime'
import { checkMemoryServiceAvailability } from '@/services/api/configApi'
import { i18n } from '@/i18n'
import { ref } from 'vue'

// Async state for Qdrant availability
const memoryServiceAvailable = ref<boolean | null>(null)
const memoryServiceLoading = ref(false)

/**
 * Load runtime configuration from backend API
 */
async function loadRuntimeConfig() {
  return getConfig()
}

const config = {
  /**
   * Application base URL - used for building full URLs (OAuth redirects, share links, etc.)
   * Returns current origin (e.g., https://example.com).
   *
   * In the native shell `window.location.origin` is `capacitor://localhost`,
   * which is useless for OAuth redirects, share links and absolute asset URLs —
   * so we return the real backend/web origin instead (Epic 3).
   */
  get appBaseUrl(): string {
    if (isNativeApp()) {
      return getNativeApiBaseUrl()
    }
    return window.location.origin
  },

  /**
   * API base URL for backend API requests
   * Empty string for same-origin (admin UI), full URL for cross-origin scenarios
   */
  get apiBaseUrl(): string {
    return getApiBaseUrl()
  },

  /**
   * Billing/Subscription configuration
   * Loaded from backend at runtime
   */
  billing: {
    get enabled(): boolean {
      return getConfigSync().billing?.enabled ?? false
    },
  },

  /**
   * Authentication surface flags (loaded from backend at runtime).
   * `registrationEnabled` is false on SSO-/OIDC-only instances
   * (REGISTRATION_ENABLED=false); defaults to true so unconfigured/older
   * backends keep offering local sign-up. The backend enforces it regardless.
   */
  auth: {
    get registrationEnabled(): boolean {
      return getConfigSync().auth?.registrationEnabled ?? true
    },
    /**
     * False when the operator disabled the anonymous guest trial
     * (GUEST_CHAT_ENABLED=false, issue #1517): guest-allowed routes then
     * require authentication like any other route. Defaults to true so
     * unconfigured/older backends keep the trial. The backend refuses the
     * guest endpoints regardless.
     */
    get guestChatEnabled(): boolean {
      return getConfigSync().auth?.guestChatEnabled ?? true
    },
  },

  /**
   * Google reCAPTCHA v3 configuration
   * Loaded from backend at runtime
   */
  recaptcha: {
    get enabled(): boolean {
      return getConfigSync().recaptcha?.enabled ?? false
    },
    get siteKey(): string {
      return getConfigSync().recaptcha?.siteKey ?? ''
    },
  },

  /**
   * Feature flags
   * Loaded from backend at runtime
   */
  features: {
    get help(): boolean {
      const features = getConfigSync().features
      return features?.help === true
    },
    get selfAware(): boolean {
      // Current backends send features.selfAware. Older ones omit it — treat
      // a missing flag as off so the hint and /help stay hidden.
      const features = getConfigSync().features as { selfAware?: boolean } | undefined
      return features?.selfAware === true
    },
    get memoryService(): boolean {
      // Return cached async check if available, otherwise fall back to config flag
      if (memoryServiceAvailable.value !== null) {
        return memoryServiceAvailable.value
      }
      const features = getConfigSync().features
      return features?.memoryService === true
    },
    get memoryServiceLoading(): boolean {
      return memoryServiceLoading.value
    },
  },

  /**
   * Speech-to-text configuration
   * webSpeechEnabled: the browser's Web Speech API may be offered (deployment flag)
   * whisperEnabled: true when local Whisper.cpp is available (record-then-transcribe)
   * speechToTextAvailable: true when ANY transcription is available (local OR API models)
   */
  speech: {
    /**
     * The browser's cloud-backed Web Speech API may be used for live
     * speech-to-text (WEB_SPEECH_ENABLED, default true; air-gapped
     * instances turn it off)
     */
    get webSpeechEnabled(): boolean {
      return getConfigSync().speech?.webSpeechEnabled ?? true
    },
    /** Local Whisper.cpp is available for record-then-transcribe mode */
    get whisperEnabled(): boolean {
      return getConfigSync().speech?.whisperEnabled ?? false
    },
    /** Any speech-to-text method is available (local Whisper OR API models like Groq/OpenAI) */
    get speechToTextAvailable(): boolean {
      return getConfigSync().speech?.speechToTextAvailable ?? false
    },
  },

  /**
   * Google Tag Manager / Google Analytics configuration
   * Loaded from backend at runtime
   */
  googleTag: {
    get enabled(): boolean {
      return getConfigSync().googleTag?.enabled ?? false
    },
    get tagId(): string {
      return getConfigSync().googleTag?.tagId ?? ''
    },
  },

  /**
   * MOBILE-APP SEAM (Epic 4): white-label branding, loaded from backend at
   * runtime (the app reads it from its configured server).
   * Defaults reproduce the historical hardcoded "Synaplan" look so an
   * unconfigured deployment is visually identical to before.
   *
   * Empty-string semantics differ by field on purpose: name/color/links
   * fall back to a default (|| ), while logo/icon/tagline keep '' so the
   * consumer can decide to use the bundled asset / render nothing.
   */
  branding: {
    get name(): string {
      return getConfigSync().branding?.name || 'Synaplan'
    },
    get tagline(): string {
      return getConfigSync().branding?.tagline ?? ''
    },
    get primaryColor(): string {
      return getConfigSync().branding?.primaryColor || '#003fc7'
    },
    get secondaryColor(): string {
      return getConfigSync().branding?.secondaryColor ?? ''
    },
    get accentColor(): string {
      return getConfigSync().branding?.accentColor ?? ''
    },
    get primaryColorDark(): string {
      return getConfigSync().branding?.primaryColorDark ?? ''
    },
    get secondaryColorDark(): string {
      return getConfigSync().branding?.secondaryColorDark ?? ''
    },
    get accentColorDark(): string {
      return getConfigSync().branding?.accentColorDark ?? ''
    },
    get fontFamily(): string {
      return getConfigSync().branding?.fontFamily ?? ''
    },
    get headingFontFamily(): string {
      return getConfigSync().branding?.headingFontFamily ?? ''
    },
    get fontUrl(): string {
      return getConfigSync().branding?.fontUrl ?? ''
    },
    get logoUrl(): string {
      return getConfigSync().branding?.logoUrl ?? ''
    },
    get logoDarkUrl(): string {
      return getConfigSync().branding?.logoDarkUrl ?? ''
    },
    get iconUrl(): string {
      return getConfigSync().branding?.iconUrl ?? ''
    },
    get homepageUrl(): string {
      return getConfigSync().branding?.homepageUrl || 'https://www.synaplan.com'
    },
    // MOBILE-APP SEAM (Epic 9.3): legal links, fail-safe to the default brand
    // pages so an unconfigured deployment still satisfies store policy.
    get privacyUrl(): string {
      return getConfigSync().branding?.privacyUrl || 'https://www.synaplan.com/privacy-policy'
    },
    get termsUrl(): string {
      return getConfigSync().branding?.termsUrl || 'https://www.synaplan.com/terms'
    },
    // MOBILE-APP SEAM (Epic 9.1): account-deletion link (Google Play policy).
    // Empty config falls back to the app's own public /account-deletion page so
    // an unconfigured deployment is still store-compliant; white-label brands
    // may point at their own deletion page.
    get accountDeletionUrl(): string {
      // Defensive read: older backend deployments omit a `type` on this field in
      // their OpenAPI spec, so the generated schema types it as `{}` instead of
      // `string`. A plain `|| fallback` would then return the object. Narrow to a
      // non-empty string explicitly so the value is correct regardless of drift.
      const url = getConfigSync().branding?.accountDeletionUrl
      return typeof url === 'string' && url ? url : '/account-deletion'
    },
    get landingPage(): string {
      return getConfigSync().branding?.landingPage ?? ''
    },
    get defaultRoute(): string {
      return getConfigSync().branding?.defaultRoute ?? ''
    },
    get showPoweredBy(): boolean {
      return getConfigSync().branding?.showPoweredBy ?? true
    },
    get poweredByLabel(): string {
      return getConfigSync().branding?.poweredByLabel || 'Synaplan'
    },
    get poweredByUrl(): string {
      return getConfigSync().branding?.poweredByUrl || 'https://www.synaplan.com'
    },
  },

  /**
   * Guest-landing marketing news master switch (admin-controlled, off by default)
   */
  marketingNews: {
    get enabled(): boolean {
      return getConfigSync().marketingNews?.enabled ?? false
    },
  },

  /**
   * In-chat usage taximeter master switch (admin-controlled, ON by default).
   * When false, the consumption bar/ring and per-message token-cost badge are
   * not rendered and no usage-summary request is made.
   */
  usageTaximeter: {
    get enabled(): boolean {
      return getConfigSync().usageTaximeter?.enabled ?? true
    },
  },

  /**
   * MOBILE-APP SEAM (App Review 5.1.2(i)): the AI providers a user's input can
   * reach on the configured server, by name. The native consent screen has to
   * name them, and it runs before sign-in — so this comes from the public
   * runtime config rather than a list baked into the bundle. Empty until the
   * config loads or when the instance has no selectable chat models; callers
   * then fall back to wording without names.
   */
  aiDisclosure: {
    get providers(): string[] {
      // Checked rather than cast: a server older than this field omits it, and
      // the schema passes unknown keys through untyped (same reason
      // `unavailableProviders` is read defensively in httpClient).
      const providers = getConfigSync().aiProviders

      return Array.isArray(providers) ? providers : []
    },
  },

  /**
   * First-run setup status (authenticated users only).
   * chatReady is false when no real AI provider can serve the current user's
   * effective default chat model (per-user override, then global default) —
   * a cloud key or a pulled local Ollama model. The built-in demo responder
   * does not count. The chat then shows a full-page setup tombstone and
   * admins are pointed at /admin/setup.
   * Defaults to true so anonymous pages and the pre-config phase never flash
   * the banner.
   */
  setup: {
    get chatReady(): boolean {
      return getConfigSync().setup?.chatReady ?? true
    },
    /**
     * Public first-run signal: the login page may show the seeded
     * administrator. Always false in production, and false once that
     * password has been changed.
     */
    get demoLoginHint(): boolean {
      return getConfigSync().setup?.demoLoginHint === true
    },
  },

  /**
   * Installed plugins for the current user
   */
  get plugins(): NonNullable<ReturnType<typeof getConfigSync>['plugins']> {
    return getConfigSync().plugins ?? []
  },

  /**
   * Build and deployment information
   * Used for debugging which version is deployed on which server
   */
  build: {
    get version(): string {
      return getConfigSync().build?.version ?? 'unknown'
    },
    get ip(): string {
      return getConfigSync().build?.ip ?? 'dev'
    },
  },

  /**
   * Client identity (server-confirmed, from the User-Agent).
   *
   * Use this for SECURITY-relevant / server-truthful decisions (e.g. payment channel
   * gating). For pure client-side UI gating prefer Capacitor.isNativePlatform() — but
   * never trust the client for anything the backend must enforce.
   */
  client: {
    /** True when the backend saw the official "Synaplan Mobile Vx.x" User-Agent. */
    get isMobileApp(): boolean {
      return getConfigSync().client?.isMobileApp ?? false
    },
    /** Parsed app version (major.minor[.patch]) or null on web. */
    get appVersion(): string | null {
      return getConfigSync().client?.appVersion ?? null
    },
    /** 'mobile' | 'web' (defaults to 'web' until config loads). */
    get platform(): string {
      return getConfigSync().client?.platform ?? 'web'
    },
  },

  /**
   * Forced-update gate (Epic 8.2), server-driven.
   *
   * `updateRequired` is computed by the backend (parsed UA version vs the
   * operator-configured minimum), so the app blocks too-old installs with a
   * "please update" screen. Defaults are inert (no gate) until config loads.
   */
  mobile: {
    /** Configured minimum supported app version, or '' when no gate is set. */
    get minVersion(): string {
      return getConfigSync().mobile?.minVersion ?? ''
    },
    /** True when this mobile app is older than minVersion and must update. */
    get updateRequired(): boolean {
      return getConfigSync().mobile?.updateRequired ?? false
    },
    /** ISO-8601 deadline after which the forced-update gate may block. */
    get updateEnforceAfter(): string {
      return getConfigSync().mobile?.updateEnforceAfter ?? ''
    },
    /** App Store link for the update button ('' when unset). */
    get iosAppUrl(): string {
      return getConfigSync().mobile?.iosAppUrl ?? ''
    },
    /** Play Store link for the update button ('' when unset). */
    get androidAppUrl(): string {
      return getConfigSync().mobile?.androidAppUrl ?? ''
    },
  },

  /**
   * Load runtime configuration from backend
   * Call this before accessing config values (e.g., in main.ts)
   *
   * Note: Qdrant check is NOT done here - it's done in reload()
   * which is called after successful authentication. This prevents
   * unnecessary API calls on public pages (shared chats, login, etc.)
   */
  async init(): Promise<void> {
    await loadRuntimeConfig()
  },

  /**
   * Check Qdrant availability asynchronously (non-blocking)
   * This runs in background and updates memoryServiceAvailable when done
   */
  async checkMemoryServiceAsync(): Promise<void> {
    if (!getConfigSync().features?.memoryService) {
      // Not configured at all - no need to check
      memoryServiceAvailable.value = false
      return
    }

    memoryServiceLoading.value = true
    try {
      const result = await checkMemoryServiceAvailability()
      memoryServiceAvailable.value = result.available
    } catch (err) {
      console.warn('⚠️ Qdrant availability check failed:', err)
      memoryServiceAvailable.value = false
    } finally {
      memoryServiceLoading.value = false
    }
  },

  /**
   * Reload runtime configuration (clears cache)
   * Call this after login to get user-specific config like plugins
   */
  async reload(): Promise<void> {
    await reloadConfig()
    this.checkMemoryServiceAsync()
    this.checkUnavailableProviders()
  },

  checkUnavailableProviders(): void {
    const unavailable = getUnavailableProviders()
    if (unavailable.length > 0) {
      console.warn(
        `[Synaplan] ${i18n.global.t('system.missingApiKeys', { providers: unavailable.join(', ') })}`
      )
    }
  },

  /**
   * Development-only features
   */
  dev: {
    autoLogin: import.meta.env.VITE_AUTO_LOGIN_DEV === 'true',
  },
}

// Alias for backwards compatibility / future Pinia migration
export const useConfigStore = () => config

export default config
