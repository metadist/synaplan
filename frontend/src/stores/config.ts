/**
 * Application Configuration
 *
 * Centralized configuration management.
 * Configuration is loaded from backend API at runtime (no build-time env vars needed).
 */

import { z } from 'zod'

// Runtime config schema based on OpenAPI annotations from ConfigController
const RuntimeConfigSchema = z.object({
  recaptcha: z.object({
    enabled: z.boolean(),
    siteKey: z.string(),
  }),
  features: z.object({
    help: z.boolean(),
  }),
})

type RuntimeConfig = z.infer<typeof RuntimeConfigSchema>

let runtimeConfig: RuntimeConfig | null = null
let configPromise: Promise<RuntimeConfig> | null = null

/**
 * Load runtime configuration from backend API
 * Uses fetch directly to avoid circular dependency with httpClient
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

  // Fetch config from backend with Zod validation
  configPromise = fetch('/api/v1/config/runtime')
    .then((response) => {
      if (!response.ok) {
        throw new Error(`Failed to load runtime config: ${response.status}`)
      }
      return response.json()
    })
    .then((data) => {
      // Validate response with Zod schema
      const validated = RuntimeConfigSchema.parse(data)
      runtimeConfig = validated
      return validated
    })
    .catch((error) => {
      console.error('Failed to load runtime config:', error)
      // Return default config on error
      const defaultConfig: RuntimeConfig = {
        recaptcha: {
          enabled: false,
          siteKey: '',
        },
        features: {
          help: false,
        },
      }
      runtimeConfig = defaultConfig
      return defaultConfig
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
   * Feature flags
   * Loaded from backend at runtime
   */
  features: {
    get help(): boolean {
      return runtimeConfig?.features.help ?? false
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
