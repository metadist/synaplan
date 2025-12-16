// Admin API service
import { httpClient } from './httpClient'

export interface AdminUser {
  id: number
  email: string
  level: string
  type: string
  providerId: string
  emailVerified: boolean
  created: string
  isAdmin: boolean
  locale: string
}

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
  async getUsers(
    page = 1,
    limit = 50,
    search = ''
  ): Promise<{ users: AdminUser[]; total: number; page: number; limit: number }> {
    const params = new URLSearchParams({
      page: page.toString(),
      limit: limit.toString(),
    })
    if (search) {
      params.append('search', search)
    }
    return httpClient<{ users: AdminUser[]; total: number; page: number; limit: number }>(
      `/api/v1/admin/users?${params}`
    )
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
