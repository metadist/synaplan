/**
 * Application Configuration
 *
 * Centralized configuration management supporting both:
 * 1. Runtime configuration via window.__RUNTIME_CONFIG__ (injected at container startup)
 * 2. Build-time environment variables (fallback for development)
 *
 * Plain object for now to avoid Pinia initialization issues when used at module level.
 * Can be converted to Pinia store later when all usage is inside component/function scope.
 */

// Type declaration for runtime config
declare global {
  interface Window {
    __RUNTIME_CONFIG__?: {
      RECAPTCHA_ENABLED?: string
      RECAPTCHA_SITE_KEY?: string
    }
  }
}

/**
 * Get a runtime config value, falling back to build-time env var.
 * Runtime values are injected into index.html at container startup.
 * Values containing '__' prefix are placeholders that weren't replaced.
 */
function getRuntimeConfig(key: string, buildTimeValue: string): string {
  const runtimeValue = window.__RUNTIME_CONFIG__?.[key as keyof typeof window.__RUNTIME_CONFIG__]

  // If runtime value exists and isn't a placeholder (still has __), use it
  if (runtimeValue && !runtimeValue.startsWith('__')) {
    return runtimeValue
  }

  // Fall back to build-time env var
  return buildTimeValue
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
   * Supports runtime configuration for Docker deployments
   */
  recaptcha: {
    get enabled(): boolean {
      const value = getRuntimeConfig(
        'RECAPTCHA_ENABLED',
        import.meta.env.VITE_RECAPTCHA_ENABLED || ''
      )
      return value === 'true'
    },
    get siteKey(): string {
      return getRuntimeConfig('RECAPTCHA_SITE_KEY', import.meta.env.VITE_RECAPTCHA_SITE_KEY || '')
    },
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
