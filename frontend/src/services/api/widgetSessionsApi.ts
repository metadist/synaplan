import { httpClient } from './httpClient'

export interface WidgetSession {
  id: number
  sessionId: string
  sessionIdDisplay?: string
  chatId: number | null
  messageCount: number
  fileCount: number
  mode: 'ai' | 'human' | 'waiting'
  humanOperatorId: number | null
  lastMessage: number
  lastMessagePreview: string | null
  lastHumanActivity: number | null
  created: number
  expires: number
  isExpired: boolean
  isFavorite: boolean
  country: string | null
  title: string | null
}

export interface SessionMessageFile {
  id: number
  filename: string
  mimeType: string
  size: number
}

export interface SessionMessage {
  id: number
  direction: 'IN' | 'OUT'
  text: string
  timestamp: number
  sender: 'user' | 'ai' | 'human' | 'system'
  files?: SessionMessageFile[]
}

export interface WidgetSessionsResponse {
  success: boolean
  sessions: WidgetSession[]
  pagination: {
    total: number
    limit: number
    offset: number
    hasMore: boolean
  }
  stats: {
    ai: number
    human: number
    waiting: number
  }
}

export interface WidgetSessionDetailResponse {
  success: boolean
  session: WidgetSession
  messages: SessionMessage[]
}

export interface ListSessionsParams {
  limit?: number
  offset?: number
  status?: 'active' | 'expired'
  mode?: 'ai' | 'human' | 'waiting'
  from?: number
  to?: number
  sort?: 'lastMessage' | 'created' | 'messageCount'
  order?: 'ASC' | 'DESC'
  favorite?: boolean
}

/**
 * List all sessions for a widget
 */
export async function listWidgetSessions(
  widgetId: string,
  params: ListSessionsParams = {}
): Promise<WidgetSessionsResponse> {
  const queryParams = new URLSearchParams()

  if (params.limit !== undefined) queryParams.set('limit', String(params.limit))
  if (params.offset !== undefined) queryParams.set('offset', String(params.offset))
  if (params.status) queryParams.set('status', params.status)
  if (params.mode) queryParams.set('mode', params.mode)
  if (params.from !== undefined) queryParams.set('from', String(params.from))
  if (params.to !== undefined) queryParams.set('to', String(params.to))
  if (params.sort) queryParams.set('sort', params.sort)
  if (params.order) queryParams.set('order', params.order)
  if (params.favorite !== undefined) queryParams.set('favorite', String(params.favorite))

  const queryString = queryParams.toString()
  const url = `/api/v1/widgets/${widgetId}/sessions${queryString ? `?${queryString}` : ''}`

  return await httpClient<WidgetSessionsResponse>(url, {
    method: 'GET',
  })
}

/**
 * Get session details with full chat history
 */
export async function getWidgetSession(
  widgetId: string,
  sessionId: string
): Promise<WidgetSessionDetailResponse> {
  return await httpClient<WidgetSessionDetailResponse>(
    `/api/v1/widgets/${widgetId}/sessions/${sessionId}`,
    {
      method: 'GET',
    }
  )
}

/**
 * Take over a session (switch from AI to human mode)
 */
export async function takeOverSession(
  widgetId: string,
  sessionId: string
): Promise<{ success: boolean; session: WidgetSession }> {
  return await httpClient<{ success: boolean; session: WidgetSession }>(
    `/api/v1/widgets/${widgetId}/sessions/${sessionId}/takeover`,
    {
      method: 'POST',
    }
  )
}

/**
 * Hand back session to AI
 */
export async function handBackSession(
  widgetId: string,
  sessionId: string
): Promise<{ success: boolean; session: WidgetSession }> {
  return await httpClient<{ success: boolean; session: WidgetSession }>(
    `/api/v1/widgets/${widgetId}/sessions/${sessionId}/handback`,
    {
      method: 'POST',
    }
  )
}

/**
 * Toggle favorite status for a session
 */
export async function toggleFavorite(
  widgetId: string,
  sessionId: string
): Promise<{ success: boolean; isFavorite: boolean }> {
  return await httpClient<{ success: boolean; isFavorite: boolean }>(
    `/api/v1/widgets/${widgetId}/sessions/${sessionId}/favorite`,
    {
      method: 'POST',
    }
  )
}

/**
 * Send a message as human operator
 */
export async function sendHumanMessage(
  widgetId: string,
  sessionId: string,
  text: string,
  fileIds: number[] = []
): Promise<{ success: boolean; messageId: number }> {
  return await httpClient<{ success: boolean; messageId: number }>(
    `/api/v1/widgets/${widgetId}/sessions/${sessionId}/reply`,
    {
      method: 'POST',
      body: JSON.stringify({ text, files: fileIds }),
    }
  )
}

/**
 * Upload a file as operator for a session
 */
export async function uploadOperatorFile(
  widgetId: string,
  sessionId: string,
  file: File
): Promise<{ success: boolean; fileId: number; filename: string }> {
  const formData = new FormData()
  formData.append('file', file)

  const response = await fetch(`/api/v1/widgets/${widgetId}/sessions/${sessionId}/upload`, {
    method: 'POST',
    body: formData,
    credentials: 'include',
  })

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({ error: 'Upload failed' }))
    throw new Error(errorData.error || 'Upload failed')
  }

  return response.json()
}

/**
 * Rename a session (update title)
 */
export async function renameSession(
  widgetId: string,
  sessionId: string,
  title: string | null
): Promise<{ success: boolean; title: string | null }> {
  return await httpClient<{ success: boolean; title: string | null }>(
    `/api/v1/widgets/${widgetId}/sessions/${sessionId}/rename`,
    {
      method: 'PATCH',
      body: JSON.stringify({ title }),
    }
  )
}

/**
 * Send typing indicator from operator to widget user
 */
export async function sendOperatorTyping(
  widgetId: string,
  sessionId: string,
  isTyping: boolean = true
): Promise<{ success: boolean }> {
  return await httpClient<{ success: boolean }>(
    `/api/v1/widgets/${widgetId}/sessions/${sessionId}/typing`,
    {
      method: 'POST',
      body: JSON.stringify({ isTyping }),
    }
  )
}

export interface ExportFormat {
  id: 'xlsx' | 'csv' | 'json'
  name: string
  description: string
  recommended: boolean
}

export interface ExportParams {
  format?: 'xlsx' | 'csv' | 'json'
  from?: number
  to?: number
  mode?: 'ai' | 'human' | 'waiting'
  sessionIds?: string[]
}

/**
 * Get available export formats
 */
export async function getExportFormats(
  widgetId: string
): Promise<{ success: boolean; formats: ExportFormat[] }> {
  return await httpClient<{ success: boolean; formats: ExportFormat[] }>(
    `/api/v1/widgets/${widgetId}/export/formats`,
    {
      method: 'GET',
    }
  )
}

/**
 * Export widget sessions
 * Returns the download URL
 */
export function getExportUrl(widgetId: string, params: ExportParams = {}): string {
  const queryParams = new URLSearchParams()

  if (params.format) queryParams.set('format', params.format)
  if (params.from !== undefined) queryParams.set('from', String(params.from))
  if (params.to !== undefined) queryParams.set('to', String(params.to))
  if (params.mode) queryParams.set('mode', params.mode)
  if (params.sessionIds && params.sessionIds.length > 0) {
    queryParams.set('sessionIds', params.sessionIds.join(','))
  }

  const queryString = queryParams.toString()
  return `/api/v1/widgets/${widgetId}/export${queryString ? `?${queryString}` : ''}`
}

/**
 * Delete multiple sessions
 */
export async function deleteSessions(
  widgetId: string,
  sessionIds: string[]
): Promise<{ success: boolean; deleted: number }> {
  return await httpClient<{ success: boolean; deleted: number }>(
    `/api/v1/widgets/${widgetId}/sessions`,
    {
      method: 'DELETE',
      body: JSON.stringify({ sessionIds }),
    }
  )
}

// Summary types
export interface WidgetSummary {
  id?: number
  date?: number
  formattedDate?: string
  sessionCount: number
  messageCount: number
  userMessages?: number
  assistantMessages?: number
  topics: string[]
  faqs: Array<{ question: string; frequency: number }>
  sentiment: { positive: number; neutral: number; negative: number }
  issues: string[]
  recommendations: string[]
  summary: string
  promptSuggestions?: Array<{ type: string; suggestion: string }>
  fromDate?: number
  toDate?: number
  dateRange?: string
  created?: number
}

export interface AnalyzeSummaryParams {
  sessionIds?: string[]
  fromDate?: number
  toDate?: number
  summaryId?: number
}

/**
 * Get recent summaries for a widget
 */
export async function getWidgetSummaries(
  widgetId: string,
  limit: number = 7
): Promise<{ success: boolean; summaries: WidgetSummary[] }> {
  return await httpClient<{ success: boolean; summaries: WidgetSummary[] }>(
    `/api/v1/widgets/${widgetId}/summaries?limit=${limit}`,
    {
      method: 'GET',
    }
  )
}

/**
 * Get summary for a specific date
 */
export async function getWidgetSummaryByDate(
  widgetId: string,
  date: number
): Promise<{ success: boolean; summary: WidgetSummary }> {
  return await httpClient<{ success: boolean; summary: WidgetSummary }>(
    `/api/v1/widgets/${widgetId}/summaries/${date}`,
    {
      method: 'GET',
    }
  )
}

/**
 * Generate a summary for a specific date
 */
export async function generateWidgetSummary(
  widgetId: string,
  date?: number
): Promise<{ success: boolean; summary: WidgetSummary }> {
  return await httpClient<{ success: boolean; summary: WidgetSummary }>(
    `/api/v1/widgets/${widgetId}/summaries/generate`,
    {
      method: 'POST',
      body: JSON.stringify(date ? { date } : {}),
    }
  )
}

/**
 * Generate AI analysis for selected sessions or date range
 */
export async function analyzeWidgetSessions(
  widgetId: string,
  params: AnalyzeSummaryParams
): Promise<{ success: boolean; summary: WidgetSummary }> {
  return await httpClient<{ success: boolean; summary: WidgetSummary }>(
    `/api/v1/widgets/${widgetId}/summaries/analyze`,
    {
      method: 'POST',
      body: JSON.stringify(params),
    }
  )
}
