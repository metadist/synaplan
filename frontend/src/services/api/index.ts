/**
 * API Services - Central Export
 *
 * Organized by functionality:
 * - authApi: Authentication & user sessions
 * - profileApi: User profile management
 * - chatApi: Messages & conversations
 * - legacyApi: Backward compatibility
 */

export { authApi } from './authApi'
export { profileApi } from './profileApi'
export { chatApi } from './chatApi'
export { legacyApi } from './legacyApi'
export * from './configApi'
export * from './apiKeysApi'
export { httpClient, getApiBaseUrl, setApiBaseUrl } from './httpClient'

// Re-export for backward compatibility
export const api = {
  auth: () => import('./authApi').then((m) => m.authApi),
  profile: () => import('./profileApi').then((m) => m.profileApi),
  chat: () => import('./chatApi').then((m) => m.chatApi),
  legacy: () => import('./legacyApi').then((m) => m.legacyApi),
}
