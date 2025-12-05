/**
 * Application Configuration
 *
 * Centralized configuration management. Currently reads from build-time
 * environment variables, but designed to support runtime configuration
 * loading in the future.
 *
 * Plain object for now to avoid Pinia initialization issues when used at module level.
 * Can be converted to Pinia store later when all usage is inside component/function scope.
 */

export const config = {
  /**
   * Application base URL - used for building full URLs (OAuth redirects, share links, etc.)
   * Empty string = same-origin (frontend and backend served from same domain)
   * Always normalized (no trailing slash)
   */
  appBaseUrl: '',

  /**
   * API base URL for backend API requests.
   * Same as appBaseUrl (endpoints include /api/ prefix)
   * Empty string = same-origin API (e.g., /api/v1/...)
   * Always normalized (no trailing slash)
   */
  apiBaseUrl: '',

  /**
   * Google reCAPTCHA v3 configuration
   */
  recaptcha: {
    enabled: import.meta.env.VITE_RECAPTCHA_ENABLED === 'true',
    siteKey: import.meta.env.VITE_RECAPTCHA_SITE_KEY || '',
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
