const API_BASE = import.meta.env.VITE_API_URL || ''

function getAuthToken(): string | null {
  return localStorage.getItem('auth_token')
}

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
  const token = getAuthToken()
  
  if (!token) {
    throw new Error('Not authenticated. Please log in.')
  }
  
  const response = await fetch(`${API_BASE}/api/v1/usage/stats`, {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  })

  if (response.status === 401) {
    throw new Error('Session expired. Please log in again.')
  }

  if (!response.ok) {
    const errorText = await response.text()
    throw new Error(`Failed to fetch usage stats: ${response.statusText} - ${errorText}`)
  }

  const data: UsageResponse = await response.json()
  return data.data
}

export function getExportCsvUrl(sinceTimestamp?: number): string {
  const token = getAuthToken()
  let url = `${API_BASE}/api/v1/usage/export?token=${token}`
  
  if (sinceTimestamp) {
    url += `&since=${sinceTimestamp}`
  }
  
  return url
}

export async function downloadUsageExport(sinceTimestamp?: number): Promise<void> {
  const token = getAuthToken()
  let url = `${API_BASE}/api/v1/usage/export`
  
  const params = new URLSearchParams()
  if (sinceTimestamp) {
    params.append('since', sinceTimestamp.toString())
  }
  
  if (params.toString()) {
    url += `?${params.toString()}`
  }

  const response = await fetch(url, {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${token}`
    }
  })

  if (!response.ok) {
    throw new Error(`Failed to export usage: ${response.statusText}`)
  }

  const blob = await response.blob()
  const downloadUrl = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = downloadUrl
  link.download = `synaplan-usage-${Date.now()}.csv`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  window.URL.revokeObjectURL(downloadUrl)
}

