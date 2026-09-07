import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  ApprovePlatformInstanceResponseSchema,
  CreatePlatformLinkCodeResponseSchema,
  DisconnectMyPlatformLinkResponseSchema,
  GetPlatformInstancePublicResponseSchema,
  ListAdminPlatformInstancesResponseSchema,
  ListMyPlatformLinksResponseSchema,
  RevokePlatformInstanceResponseSchema,
} from '@/generated/api-schemas'

export type PlatformLink = NonNullable<
  z.infer<typeof ListMyPlatformLinksResponseSchema>['links']
>[number]

export type AdminPlatformInstance = NonNullable<
  z.infer<typeof ListAdminPlatformInstancesResponseSchema>['instances']
>[number]

export const platformLinksApi = {
  async getPublicInstance(instanceId: string): Promise<{ client: string; host: string }> {
    const data = await httpClient(`/api/v1/platform-links/instances/${instanceId}/public`, {
      method: 'GET',
      schema: GetPlatformInstancePublicResponseSchema,
    })
    return { client: data.client ?? '', host: data.host ?? '' }
  },

  async createLinkCode(payload: {
    instanceId: string
    externalId: string
    redirectUri: string
    state: string
    withMemories?: boolean
  }): Promise<{ redirect: string }> {
    const data = await httpClient('/api/v1/platform-links/codes', {
      method: 'POST',
      body: JSON.stringify({
        instance_id: payload.instanceId,
        external_id: payload.externalId,
        redirect_uri: payload.redirectUri,
        state: payload.state,
        with_memories: payload.withMemories ?? false,
      }),
      schema: CreatePlatformLinkCodeResponseSchema,
    })
    return { redirect: data.redirect ?? '' }
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
}
