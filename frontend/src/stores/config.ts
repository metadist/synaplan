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
import { checkMemoryServiceAvailability } from '@/services/api/configApi'
import { useNotification } from '@/composables/useNotification'
import { i18n } from '@/i18n'
import { ref } from 'vue'

// Async state for memory service availability
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
   * Returns current origin (e.g., https://example.com)
   */
  get appBaseUrl(): string {
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
   * whisperEnabled: true when local Whisper.cpp is available (record-then-transcribe)
   * speechToTextAvailable: true when ANY transcription is available (local OR API models)
   */
  speech: {
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
   * Load runtime configuration from backend
   * Call this before accessing config values (e.g., in main.ts)
   *
   * Note: Memory service check is NOT done here - it's done in reload()
   * which is called after successful authentication. This prevents
   * unnecessary API calls on public pages (shared chats, login, etc.)
   */
  async init(): Promise<void> {
    await loadRuntimeConfig()
  },

  /**
   * Check memory service availability asynchronously (non-blocking)
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
      console.warn('⚠️ Memory service check failed:', err)
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
      const { warning } = useNotification()
      const t = i18n.global.t
      warning(t('system.missingApiKeys', { providers: unavailable.join(', ') }), 10000)
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
