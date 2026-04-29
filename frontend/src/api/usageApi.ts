import { httpClient } from '@/services/api/httpClient'
import { useConfigStore } from '@/stores/config'

export type SubscriptionStatus =
  | 'active'
  | 'free'
  | 'past_due'
  | 'cancelled'
  | 'anonymous'
  | 'inactive'

export interface UsageStats {
  user_level: string
  phone_verified: boolean
  subscription: {
    level: string
    active: boolean
    status: SubscriptionStatus
    plan_name: string
    expires_at: number | null
    stripe_customer_id: string | null
  }
  usage: Record<
    string,
    {
      used: number
      limit: number
      remaining: number
      allowed: boolean
      resets_at: number | null
      type: string
    }
  >
  limits: Record<string, number>
  remaining: Record<string, number>
  breakdown: {
    by_source: Record<
      string,
      {
        total: number
        actions: Record<string, number>
      }
    >
    by_time: Record<
      string,
      {
        total: number
        actions: Record<string, number>
      }
    >
  }
  recent_usage: Array<{
    timestamp: number
    datetime: string
    action: string
    source: string
    model: string
    tokens: number
    prompt_tokens: number
    completion_tokens: number
    cached_tokens: number
    cache_creation_tokens: number
    estimated: boolean
    cost: number
    latency: number
    status: string
  }>
  /**
   * Sum across all six tracked action types (MESSAGES + IMAGES + VIDEOS + AUDIOS
   * + FILE_ANALYSIS + EMBEDDINGS). Do NOT use for the headline "chat messages
   * used" number — use `total_messages` instead so it matches the free-tier
   * limit (50/50) surfaced in LimitReachedModal.
   */
  total_requests: number
  /** Chat messages consumed (BACTION = 'MESSAGES'); same counter gated by the rate limit. */
  total_messages: number
  cost_budget: {
    used: number
    budget: number
    remaining: number
    percent: number
    period_start: number
    period_end: number
  }
  cost_summary: {
    today: number
    this_week: number
    this_month: number
    cache_savings: number
  }
}

interface UsageResponse {
  success: boolean
  data: UsageStats
}

export async function getUsageStats(): Promise<UsageStats> {
  const data = await httpClient<UsageResponse>('/api/v1/usage/stats', {
    method: 'GET',
  })
  return data.data
}

/**
 * Get export CSV URL with auth token
 * @deprecated Use downloadUsageExport() instead for secure downloads
 */
export async function getExportCsvUrl(sinceTimestamp?: number): Promise<string> {
  const config = useConfigStore()
  const basePath = config.apiBaseUrl || '/api'

  // Fetch SSE token for URL-based auth (needed for direct links)
  const tokenResponse = await fetch(`${basePath}/v1/auth/token`, {
    credentials: 'include',
  })

  if (!tokenResponse.ok) {
    throw new Error('Authentication required')
  }

  const { token } = await tokenResponse.json()
  let url = `${basePath}/v1/usage/export?token=${token}`

  if (sinceTimestamp) {
    url += `&since=${sinceTimestamp}`
  }

  return url
}

export interface ActivityEntry {
  timestamp: number
  datetime: string
  action: string
  source: string
  model: string
  tokens: number
  prompt_tokens: number
  completion_tokens: number
  cached_tokens: number
  cache_creation_tokens: number
  estimated: boolean
  cost: number
  latency: number
  status: string
}

export interface ActivityLogResponse {
  items: ActivityEntry[]
  total: number
  page: number
  per_page: number
  total_pages: number
}

export interface ActivityFilters {
  page?: number
  per_page?: number
  search?: string
  action?: string
  from?: number
  to?: number
}

export async function getActivityLog(filters: ActivityFilters = {}): Promise<ActivityLogResponse> {
  const params: Record<string, string> = {}
  if (filters.page) params.page = filters.page.toString()
  if (filters.per_page) params.per_page = filters.per_page.toString()
  if (filters.search) params.search = filters.search
  if (filters.action) params.action = filters.action
  if (filters.from) params.from = filters.from.toString()
  if (filters.to) params.to = filters.to.toString()

  const data = await httpClient<{ success: boolean; data: ActivityLogResponse }>(
    '/api/v1/usage/activity',
    { method: 'GET', params }
  )
  return data.data
}

export async function downloadUsageExport(sinceTimestamp?: number): Promise<void> {
  const params: Record<string, string> = {}
  if (sinceTimestamp) {
    params.since = sinceTimestamp.toString()
  }

  const blob = await httpClient<Blob>('/api/v1/usage/export', {
    method: 'GET',
    params,
    responseType: 'blob',
  })

  const downloadUrl = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = downloadUrl
  link.download = `synaplan-usage-${Date.now()}.csv`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  window.URL.revokeObjectURL(downloadUrl)
}
