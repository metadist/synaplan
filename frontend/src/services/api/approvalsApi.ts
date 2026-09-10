import { z } from 'zod'
import { httpClient } from './httpClient'

const RequestedBySchema = z.object({
  kind: z.string(),
  chatId: z.number().nullable().optional(),
  messageId: z.number().nullable().optional(),
  taskId: z.number().nullable().optional(),
  runId: z.number().nullable().optional(),
  nodeId: z.string().nullable().optional(),
})

const ApprovalSchema = z.object({
  id: z.number(),
  tool: z.string(),
  sideEffect: z.string().optional(),
  preview: z.string().nullable().optional(),
  status: z.string(),
  expiresAt: z.number(),
  created: z.number(),
  decidedAt: z.number().nullable().optional(),
  requestedBy: RequestedBySchema,
  canAlwaysAllow: z.boolean().optional(),
})

const ListSchema = z.object({
  success: z.boolean(),
  pendingCount: z.number(),
  approvals: z.array(ApprovalSchema),
})

const OneSchema = z.object({
  success: z.boolean(),
  approval: ApprovalSchema,
})

const NotifySchema = z.object({
  success: z.boolean(),
  mode: z.enum(['instant', 'digest']),
})

export type Approval = z.infer<typeof ApprovalSchema>

export const approvalsApi = {
  async list(status: 'pending' | 'decided' = 'pending'): Promise<{
    pendingCount: number
    approvals: Approval[]
  }> {
    const data = await httpClient('/api/v1/approvals', {
      params: { status },
      schema: ListSchema,
    })
    return { pendingCount: data.pendingCount, approvals: data.approvals }
  },

  async approve(id: number, alwaysAllow = false, assistantKey?: string): Promise<Approval> {
    const data = await httpClient(`/api/v1/approvals/${id}/approve`, {
      method: 'POST',
      body: JSON.stringify({ alwaysAllow, assistantKey: assistantKey ?? null }),
      schema: OneSchema,
    })
    return data.approval
  },

  async reject(id: number, reason?: string): Promise<Approval> {
    const data = await httpClient(`/api/v1/approvals/${id}/reject`, {
      method: 'POST',
      body: JSON.stringify({ reason: reason ?? null }),
      schema: OneSchema,
    })
    return data.approval
  },

  async getNotifyMode(): Promise<'instant' | 'digest'> {
    const data = await httpClient('/api/v1/approvals/notify-setting', { schema: NotifySchema })
    return data.mode
  },

  async setNotifyMode(mode: 'instant' | 'digest'): Promise<'instant' | 'digest'> {
    const data = await httpClient('/api/v1/approvals/notify-setting', {
      method: 'PATCH',
      body: JSON.stringify({ mode }),
      schema: NotifySchema,
    })
    return data.mode
  },
}
