import { httpClient } from '@/services/api/httpClient'
import { useConfigStore } from '@/stores/config'

export interface UsageStats {
  user_level: string
  phone_verified: boolean
  subscription: {
    level: string
    active: boolean
    plan_name: string
    expires_at: number | null
    stripe_customer_id: string | null
  }
  usage: Record<string, {
    used: number
    limit: number
    remaining: number
    allowed: boolean
    resets_at: number | null
    type: string
  }>
  limits: Record<string, number>
  remaining: Record<string, number>
  breakdown: {
    by_source: Record<string, {
      total: number
      actions: Record<string, number>
    }>
    by_time: Record<string, {
      total: number
      actions: Record<string, number>
    }>
  }
  recent_usage: Array<{
    timestamp: number
    datetime: string
    action: string
    source: string
    model: string
    tokens: number
    cost: number
    latency: number
    status: string
  }>
  total_requests: number
}

interface UsageResponse {
  success: boolean
  data: UsageStats
}

export async function getUsageStats(): Promise<UsageStats> {
  const data = await httpClient<UsageResponse>('/api/v1/usage/stats', {
    method: 'GET'
  })
  return data.data
}

export function getExportCsvUrl(sinceTimestamp?: number): string {
  const config = useConfigStore()
  const token = localStorage.getItem('auth_token')
  let url = `${config.apiBaseUrl}/v1/usage/export?token=${token}`

  if (sinceTimestamp) {
    url += `&since=${sinceTimestamp}`
  }

  return url
}

export async function downloadUsageExport(sinceTimestamp?: number): Promise<void> {
  const params: Record<string, string> = {}
  if (sinceTimestamp) {
    params.since = sinceTimestamp.toString()
  }

  const blob = await httpClient<Blob>('/api/v1/usage/export', {
    method: 'GET',
    params,
    responseType: 'blob'
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

