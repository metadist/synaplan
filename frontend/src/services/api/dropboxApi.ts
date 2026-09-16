/**
 * Dropbox connector: availability + consent handover.
 *
 * The consent URL is minted per request (it carries a signed state and a PKCE
 * challenge that expire), so it is fetched at click time, never cached.
 */
import { httpClient } from './httpClient'
import {
  GetApiConnectionsDropboxStartResponseSchema,
  GetApiConnectionsDropboxStatusResponseSchema,
  PostAdminConnectionsDropboxResetResponseSchema,
} from '@/generated/api-schemas'

export interface DropboxStatus {
  /** True when an operator configured the Dropbox app. */
  available: boolean
  /** Redirect URI that must be registered in the Dropbox App Console, resolved server-side. */
  redirectUri: string
}

export const dropboxApi = {
  async status(): Promise<DropboxStatus> {
    const data = await httpClient('/api/v1/connections/dropbox/status', {
      schema: GetApiConnectionsDropboxStatusResponseSchema,
    })
    return {
      available: data.available === true,
      redirectUri: data.redirect_uri ?? '',
    }
  },

  async authorizeUrl(): Promise<string> {
    const data = await httpClient('/api/v1/connections/dropbox/start', {
      schema: GetApiConnectionsDropboxStartResponseSchema,
    })
    return data.authorize_url
  },

  /**
   * Admin only: delete every Dropbox connection on this installation so all
   * users can redo the OAuth registration freshly. Returns how many were removed.
   */
  async resetAllConnections(): Promise<number> {
    const data = await httpClient('/api/v1/admin/connections/dropbox/reset', {
      method: 'POST',
      schema: PostAdminConnectionsDropboxResetResponseSchema,
    })
    return data.removed
  },
}
