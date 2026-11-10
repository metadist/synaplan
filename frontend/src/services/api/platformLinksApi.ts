import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  ApprovePlatformInstanceHostResponseSchema,
  ApprovePlatformInstanceResponseSchema,
  ConnectOutlookAddinResponseSchema,
  DisconnectMyPlatformLinkResponseSchema,
  GetPlatformInstancePublicResponseSchema,
  ListAdminPlatformInstancesResponseSchema,
  ListMyPlatformLinksResponseSchema,
  OauthConsentDecideResponseSchema,
  OauthConsentShowResponseSchema,
  RemovePlatformInstanceHostResponseSchema,
  RevokePlatformInstanceResponseSchema,
} from '@/generated/api-schemas'

export type PlatformLink = NonNullable<
  z.infer<typeof ListMyPlatformLinksResponseSchema>['links']
>[number]

export type AdminPlatformInstance = NonNullable<
  z.infer<typeof ListAdminPlatformInstancesResponseSchema>['instances']
>[number]

export type CloudConsent = z.infer<typeof OauthConsentShowResponseSchema>

export type OutlookConnectResult = z.infer<typeof ConnectOutlookAddinResponseSchema>
export type OutlookSignInPayload = OutlookConnectResult['payload']

export const platformLinksApi = {
  /**
   * Outlook add-in connect. The server mints the add-in key and decides
   * whether the relay may receive it (allow-list in `outlook-builtin`).
   * Not gated by PLATFORM_LINKS.ENABLED.
   */
  async connectOutlook(payload: {
    state: string
    redirectUri: string
    baseUrl: string
  }): Promise<OutlookConnectResult> {
    return httpClient('/api/v1/addin/connect', {
      method: 'POST',
      body: JSON.stringify({
        state: payload.state,
        redirect_uri: payload.redirectUri || null,
        base_url: payload.baseUrl || null,
      }),
      schema: ConnectOutlookAddinResponseSchema,
    })
  },

  async getPublicInstance(instanceId: string): Promise<{ client: string; host: string }> {
    const data = await httpClient(`/api/v1/platform-links/instances/${instanceId}/public`, {
      method: 'GET',
      schema: GetPlatformInstancePublicResponseSchema,
    })
    return { client: data.client ?? '', host: data.host ?? '' }
  },

  /** What a cloud asks for: the authorization request its server pushed, by id. */
  async getCloudConsent(request: string): Promise<CloudConsent> {
    return httpClient(`/api/v1/oauth/consent/${request}`, {
      method: 'GET',
      schema: OauthConsentShowResponseSchema,
    })
  },

  /** Allow or deny; the answer is where the browser goes next (back to the cloud). */
  async decideCloudConsent(request: string, approve: boolean): Promise<{ redirect: string }> {
    const data = await httpClient(`/api/v1/oauth/consent/${request}`, {
      method: 'POST',
      body: JSON.stringify({ approve }),
      schema: OauthConsentDecideResponseSchema,
    })
    return { redirect: data.redirect }
  },

  async listMine(): Promise<PlatformLink[]> {
    const data = await httpClient('/api/v1/me/platform-links', {
      method: 'GET',
      schema: ListMyPlatformLinksResponseSchema,
    })
    return data.links ?? []
  },

  async disconnect(id: number): Promise<void> {
    await httpClient(`/api/v1/me/platform-links/${id}`, {
      method: 'DELETE',
      schema: DisconnectMyPlatformLinkResponseSchema,
    })
  },

  async turnOff(id: number): Promise<void> {
    await httpClient(`/api/v1/me/platform-links/${id}/turn-off`, {
      method: 'POST',
      schema: DisconnectMyPlatformLinkResponseSchema,
    })
  },

  async resume(id: number): Promise<void> {
    await httpClient(`/api/v1/me/platform-links/${id}/resume`, {
      method: 'POST',
      schema: DisconnectMyPlatformLinkResponseSchema,
    })
  },

  async listAdminInstances(): Promise<AdminPlatformInstance[]> {
    const data = await httpClient('/api/v1/admin/platform-links/instances', {
      method: 'GET',
      schema: ListAdminPlatformInstancesResponseSchema,
    })
    return data.instances ?? []
  },

  async approveInstance(instanceId: string): Promise<void> {
    await httpClient(`/api/v1/admin/platform-links/instances/${instanceId}/approve`, {
      method: 'POST',
      schema: ApprovePlatformInstanceResponseSchema,
    })
  },

  async revokeInstance(instanceId: string): Promise<void> {
    await httpClient(`/api/v1/admin/platform-links/instances/${instanceId}`, {
      method: 'DELETE',
      schema: RevokePlatformInstanceResponseSchema,
    })
  },

  async approveHost(instanceId: string, hostname: string): Promise<void> {
    await httpClient(`/api/v1/admin/platform-links/instances/${instanceId}/approved-hosts`, {
      method: 'POST',
      body: JSON.stringify({ hostname }),
      schema: ApprovePlatformInstanceHostResponseSchema,
    })
  },

  async removeApprovedHost(instanceId: string, hostname: string): Promise<void> {
    await httpClient(`/api/v1/admin/platform-links/instances/${instanceId}/approved-hosts`, {
      method: 'DELETE',
      body: JSON.stringify({ hostname }),
      schema: RemovePlatformInstanceHostResponseSchema,
    })
  },
}
