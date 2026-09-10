import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  GetApiApprovalsListResponseSchema,
  GetApiApprovalsNotifyGetResponseSchema,
  PatchApiApprovalsNotifyPatchResponseSchema,
  PostApiApprovalsApproveResponseSchema,
  PostApiApprovalsRejectResponseSchema,
} from '@/generated/api-schemas'

export type Approval = z.infer<typeof GetApiApprovalsListResponseSchema>['approvals'][number]

export const approvalsApi = {
  async list(status: 'pending' | 'decided' = 'pending'): Promise<{
    pendingCount: number
    approvals: Approval[]
  }> {
    const data = await httpClient('/api/v1/approvals', {
      params: { status },
      schema: GetApiApprovalsListResponseSchema,
    })
    return { pendingCount: data.pendingCount, approvals: data.approvals }
  },

  async approve(id: number, alwaysAllow = false, assistantKey?: string): Promise<Approval> {
    const data = await httpClient(`/api/v1/approvals/${id}/approve`, {
      method: 'POST',
      body: JSON.stringify({ alwaysAllow, assistantKey: assistantKey ?? null }),
      schema: PostApiApprovalsApproveResponseSchema,
    })
    return data.approval
  },

  async reject(id: number, reason?: string): Promise<Approval> {
    const data = await httpClient(`/api/v1/approvals/${id}/reject`, {
      method: 'POST',
      body: JSON.stringify({ reason: reason ?? null }),
      schema: PostApiApprovalsRejectResponseSchema,
    })
    return data.approval
  },

  async getNotifyMode(): Promise<'instant' | 'digest'> {
    const data = await httpClient('/api/v1/approvals/notify-setting', {
      schema: GetApiApprovalsNotifyGetResponseSchema,
    })
    return data.mode
  },

  async setNotifyMode(mode: 'instant' | 'digest'): Promise<'instant' | 'digest'> {
    const data = await httpClient('/api/v1/approvals/notify-setting', {
      method: 'PATCH',
      body: JSON.stringify({ mode }),
      schema: PatchApiApprovalsNotifyPatchResponseSchema,
    })
    return data.mode
  },
}
