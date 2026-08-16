/**
 * Microsoft 365 connector: availability + consent handover.
 *
 * The consent URL is minted per request (it carries a signed state and a PKCE
 * challenge that expire), so it is fetched at click time, never cached.
 */
import { httpClient } from './httpClient'
import {
  GetApiConnectionsM365StartResponseSchema,
  GetApiConnectionsM365StatusResponseSchema,
} from '@/generated/api-schemas'

export interface M365Status {
  /** True when an operator configured the Azure app registration. */
  available: boolean
  /** Redirect URI that must be registered in Azure, resolved server-side. */
  redirectUri: string
}

export const m365Api = {
  async status(): Promise<M365Status> {
    const data = await httpClient('/api/v1/connections/m365/status', {
      schema: GetApiConnectionsM365StatusResponseSchema,
    })
    return {
      available: data.available === true,
      redirectUri: data.redirect_uri ?? '',
    }
  },

  async authorizeUrl(): Promise<string> {
    const data = await httpClient('/api/v1/connections/m365/start', {
      schema: GetApiConnectionsM365StartResponseSchema,
    })
    return data.authorize_url
  },
}
