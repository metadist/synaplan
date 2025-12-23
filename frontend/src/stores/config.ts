/**
 * Application Configuration
 *
 * Centralized configuration management.
 * Configuration is loaded from backend API at runtime (no build-time env vars needed).
 *
 * This is a simple wrapper around httpClient config functions to avoid circular dependency.
 */

import { getConfig, getConfigSync } from '@/services/api/httpClient'

/**
 * Load runtime configuration from backend API
 */
async function loadRuntimeConfig() {
  return getConfig()
}

const config = {
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
      return getConfigSync().features?.help ?? false
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

export default config
