import { z } from 'zod'
import {
  DeleteApiFederationPartnersDisconnectResponseSchema,
  GetApiFederationInvitePreviewResponseSchema,
  GetApiFederationMembershipResponseSchema,
  GetApiFederationPartnersResponseSchema,
  PostApiFederationInvitesCreateResponseSchema,
  PostApiFederationMembershipCloseResponseSchema,
  PostApiFederationMembershipOpenResponseSchema,
  PostApiFederationPartnersAcceptResponseSchema,
  PostApiFederationPartnersPauseResponseSchema,
  PostApiFederationPartnersResumeResponseSchema,
} from '@/generated/api-schemas'
import { ApiError, httpClient } from './httpClient'

export type PartnerMembership = z.infer<typeof GetApiFederationMembershipResponseSchema>
export type FederationPartner = z.infer<
  typeof GetApiFederationPartnersResponseSchema
>['partners'][number]
export type PartnerAction = z.infer<typeof PostApiFederationPartnersAcceptResponseSchema>
export type PartnerInvitePreview = z.infer<typeof GetApiFederationInvitePreviewResponseSchema>

const BASE = '/api/v1/federation'

export const partnersApi = {
  membership(): Promise<PartnerMembership> {
    return httpClient(`${BASE}/membership`, { schema: GetApiFederationMembershipResponseSchema })
  },

  open(name: string): Promise<z.infer<typeof PostApiFederationMembershipOpenResponseSchema>> {
    return httpClient(`${BASE}/membership/open`, {
      method: 'POST',
      body: JSON.stringify({ name }),
      schema: PostApiFederationMembershipOpenResponseSchema,
    })
  },

  close(): Promise<z.infer<typeof PostApiFederationMembershipCloseResponseSchema>> {
    return httpClient(`${BASE}/membership/close`, {
      method: 'POST',
      schema: PostApiFederationMembershipCloseResponseSchema,
    })
  },

  list(): Promise<FederationPartner[]> {
    return httpClient(`${BASE}/partners`, { schema: GetApiFederationPartnersResponseSchema }).then(
      (data) => data.partners
    )
  },

  createInvite(): Promise<z.infer<typeof PostApiFederationInvitesCreateResponseSchema>> {
    return httpClient(`${BASE}/invites`, {
      method: 'POST',
      schema: PostApiFederationInvitesCreateResponseSchema,
    })
  },

  accept(inviteUrl: string): Promise<PartnerAction> {
    return httpClient(`${BASE}/partners/accept`, {
      method: 'POST',
      body: JSON.stringify({ inviteUrl }),
      schema: PostApiFederationPartnersAcceptResponseSchema,
    })
  },

  pause(id: number): Promise<z.infer<typeof PostApiFederationPartnersPauseResponseSchema>> {
    return httpClient(`${BASE}/partners/${id}/pause`, {
      method: 'POST',
      schema: PostApiFederationPartnersPauseResponseSchema,
    })
  },

  resume(id: number): Promise<z.infer<typeof PostApiFederationPartnersResumeResponseSchema>> {
    return httpClient(`${BASE}/partners/${id}/resume`, {
      method: 'POST',
      schema: PostApiFederationPartnersResumeResponseSchema,
    })
  },

  disconnect(
    id: number
  ): Promise<z.infer<typeof DeleteApiFederationPartnersDisconnectResponseSchema>> {
    return httpClient(`${BASE}/partners/${id}`, {
      method: 'DELETE',
      schema: DeleteApiFederationPartnersDisconnectResponseSchema,
    })
  },

  preview(token: string): Promise<PartnerInvitePreview> {
    return httpClient(`${BASE}/invites/${encodeURIComponent(token)}`, {
      schema: GetApiFederationInvitePreviewResponseSchema,
    })
  },
}

export function partnerErrorCode(err: unknown): string | undefined {
  return err instanceof ApiError ? err.code : undefined
}
