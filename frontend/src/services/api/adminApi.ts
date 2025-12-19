// Admin API service
import { httpClient } from './httpClient'
import { GetAdminGetUsersResponseSchema } from '@/generated/api-schemas'
import { z } from 'zod'

// Create a more flexible schema that accepts datetime strings with or without offset
const originalUserSchema = GetAdminGetUsersResponseSchema.shape.users.element
// Use omit to remove the strict datetime field, email field, and level field, then extend with more flexible fields
const FlexibleAdminUserSchema = originalUserSchema
  .omit({ created: true, email: true, level: true })
  .extend({
    created: z.string(), // Accept any string for created date (more flexible than datetime format)
    email: z.string().nullish(), // Accept email OR phone number as string (no email format validation)
    level: z.enum(['ANONYMOUS', 'NEW', 'PRO', 'TEAM', 'BUSINESS', 'ADMIN']).catch('NEW'), // Fallback to 'NEW' if invalid
  })

export const AdminUserSchema = FlexibleAdminUserSchema
export type AdminUser = z.infer<typeof AdminUserSchema>

// Create a flexible response schema that uses the flexible user schema
const FlexibleGetAdminUsersResponseSchema = GetAdminGetUsersResponseSchema.extend({
  users: z.array(FlexibleAdminUserSchema),
})

export type GetAdminUsersResponse = z.infer<typeof FlexibleGetAdminUsersResponseSchema>

export interface SystemPrompt {
  id: number
  topic: string
  language: string
  shortDescription: string
  prompt: string
  selectionRules: string | null
}

export interface UsageStats {
  period: string
  total_requests: number
  total_tokens: number
  total_cost: number
  avg_latency: number
  byAction: Record<string, { count: number; tokens: number; cost: number }>
  byProvider: Record<string, { count: number; tokens: number; cost: number }>
  byModel: Record<string, { count: number; tokens: number; cost: number }>
  topUsers?: Array<{
    id: number
    email: string
    level: string
    requests: number
    tokens: number
    cost: number
  }>
}

export interface SystemOverview {
  totalUsers: number
  usersByLevel: Record<string, number>
  recentUsers: Array<{
    id: number
    email: string
    level: string
    created: string
    emailVerified: boolean
  }>
}

export interface RegistrationAnalytics {
  timeline: Array<{
    date: string
    count: number
    byProvider: Record<string, number>
    byType: Record<string, number>
  }>
  byProvider: Record<string, number>
  byType: Record<string, number>
  period: string
  groupBy: string
}

export const adminApi = {
  async getUsers(page = 1, limit = 50, search = ''): Promise<GetAdminUsersResponse> {
    const params = new URLSearchParams({
      page: page.toString(),
      limit: limit.toString(),
    })
    if (search) {
      params.append('search', search)
    }
    const response = await httpClient(`/api/v1/admin/users?${params}`, {
      schema: FlexibleGetAdminUsersResponseSchema,
    })
    return response
  },

  async updateUserLevel(
    userId: number,
    level: string
  ): Promise<{ success: boolean; user: AdminUser }> {
    return httpClient<{ success: boolean; user: AdminUser }>(
      `/api/v1/admin/users/${userId}/level`,
      {
        method: 'PATCH',
        body: JSON.stringify({ level }),
      }
    )
  },

  async deleteUser(userId: number): Promise<{ success: boolean; message: string }> {
    return httpClient<{ success: boolean; message: string }>(`/api/v1/admin/users/${userId}`, {
      method: 'DELETE',
    })
  },

  async getSystemPrompts(): Promise<{ prompts: SystemPrompt[] }> {
    return httpClient<{ prompts: SystemPrompt[] }>('/api/v1/admin/prompts')
  },

  async updatePrompt(
    promptId: number,
    data: Partial<SystemPrompt>
  ): Promise<{ success: boolean; prompt: SystemPrompt }> {
    return httpClient<{ success: boolean; prompt: SystemPrompt }>(
      `/api/v1/admin/prompts/${promptId}`,
      {
        method: 'PATCH',
        body: JSON.stringify(data),
      }
    )
  },

  async getUsageStats(period: 'day' | 'week' | 'month' | 'all' = 'week'): Promise<UsageStats> {
    return httpClient<UsageStats>(`/api/v1/admin/usage-stats?period=${period}`)
  },

  async getOverview(): Promise<SystemOverview> {
    return httpClient<SystemOverview>('/api/v1/admin/overview')
  },

  async getRegistrationAnalytics(
    period: '7d' | '30d' | '90d' | '1y' | 'all' = '30d',
    groupBy: 'day' | 'week' | 'month' = 'day'
  ): Promise<RegistrationAnalytics> {
    return httpClient<RegistrationAnalytics>(
      `/api/v1/admin/analytics/registrations?period=${period}&groupBy=${groupBy}`
    )
  },
}
