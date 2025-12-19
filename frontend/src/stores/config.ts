/**
 * Application Configuration
 *
 * Centralized configuration management.
 * Configuration is loaded from backend API at runtime (no build-time env vars needed).
 *
 * Plain object for now to avoid Pinia initialization issues when used at module level.
 * Can be converted to Pinia store later when all usage is inside component/function scope.
 */

// Runtime config loaded from backend
interface RuntimeConfig {
  recaptcha: {
    enabled: boolean
    siteKey: string
    }
  }

let runtimeConfig: RuntimeConfig | null = null
let configPromise: Promise<RuntimeConfig> | null = null

/**
 * Load runtime configuration from backend API
 * This is called automatically when accessing config values
 */
async function loadRuntimeConfig(): Promise<RuntimeConfig> {
  // Return cached config if already loaded
  if (runtimeConfig) {
    return runtimeConfig
  }

  // Return existing promise if already loading
  if (configPromise) {
    return configPromise
  }

  // Fetch config from backend
  configPromise = fetch('/api/v1/config/runtime')
    .then((response) => {
      if (!response.ok) {
        throw new Error(`Failed to load runtime config: ${response.status}`)
      }
      return response.json()
    })
    .then((data) => {
      runtimeConfig = data
      return data
    })
    .catch((error) => {
      console.error('Failed to load runtime config:', error)
      // Return default config on error
      runtimeConfig = {
        recaptcha: {
          enabled: false,
          siteKey: '',
        },
      }
      return runtimeConfig
    })
    .finally(() => {
      configPromise = null
    })

  return configPromise
}

const config = {
  /**
   * Application base URL - used for building full URLs (OAuth redirects, share links, etc.)
   * Empty string = same-origin (frontend and backend served from same domain)
   * Always normalized (no trailing slash)
   */
  appBaseUrl: '',

  /**
   * API base URL for backend API requests.
   * Defaults to same as appBaseUrl for same-origin deployments.
   * For widget embedding, this is typically the full backend URL.
   * Endpoints include /api/ prefix (e.g., /api/v1/...)
   * Always normalized (no trailing slash)
   */
  apiBaseUrl: '',

  /**
   * Google reCAPTCHA v3 configuration
   * Loaded from backend at runtime
   */
  recaptcha: {
    get enabled(): boolean {
      // Return cached value if available (synchronous)
      return runtimeConfig?.recaptcha.enabled ?? false
    },
    get siteKey(): string {
      // Return cached value if available (synchronous)
      return runtimeConfig?.recaptcha.siteKey ?? ''
    },
  },

  /**
   * Load runtime configuration from backend
   * Call this before accessing config values (e.g., in main.ts)
   */
  async init(): Promise<void> {
    await loadRuntimeConfig()
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
