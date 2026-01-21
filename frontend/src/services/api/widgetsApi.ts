import { httpClient } from './httpClient'
import { useConfigStore } from '@/stores/config'

// Widget Interface
export interface Widget {
  id: number
  widgetId: string
  name: string
  taskPromptTopic: string
  status: 'active' | 'inactive'
  config: WidgetConfig
  isActive: boolean
  created: number
  updated: number
  stats?: {
    active_sessions: number
    total_messages: number
  }
  allowedDomains?: string[]
}

export interface WidgetConfig {
  position?: 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right'
  primaryColor?: string
  iconColor?: string
  buttonIcon?: string
  buttonIconUrl?: string | null
  defaultTheme?: 'light' | 'dark'
  autoOpen?: boolean
  autoMessage?: string
  messageLimit?: number
  maxFileSize?: number
  allowedDomains?: string[]
  allowFileUpload?: boolean // NEW: Enable/disable file upload
  fileUploadLimit?: number
}

export interface CreateWidgetRequest {
  name: string
  taskPromptTopic?: string
  websiteUrl?: string
  config?: WidgetConfig
}

export interface QuickCreateWidgetRequest {
  name: string
  websiteUrl: string
}

export interface UpdateWidgetRequest {
  name?: string
  config?: WidgetConfig
  status?: 'active' | 'inactive'
}

export interface EmbedCodeResponse {
  success: boolean
  embedCode: string
  legacyEmbedCode?: string
  wordpressShortcode: string
  widgetUrl: string
}

/**
 * List all widgets for current user
 */
export async function listWidgets(): Promise<Widget[]> {
  const data = await httpClient<{ success: boolean; widgets: Widget[] }>('/api/v1/widgets', {
    method: 'GET',
  })
  return data.widgets
}

/**
 * Create new widget
 */
export async function createWidget(request: CreateWidgetRequest): Promise<Widget> {
  const data = await httpClient<{ success: boolean; widget: Widget }>('/api/v1/widgets', {
    method: 'POST',
    body: JSON.stringify(request),
  })
  return data.widget
}

/**
 * Quick create widget with minimal setup (name + website only)
 * Uses default prompt and auto-adds website domain to allowed list
 */
export async function quickCreateWidget(request: QuickCreateWidgetRequest): Promise<Widget> {
  const data = await httpClient<{ success: boolean; widget: Widget }>('/api/v1/widgets', {
    method: 'POST',
    body: JSON.stringify({
      name: request.name,
      websiteUrl: request.websiteUrl,
    }),
  })
  return data.widget
}

/**
 * Get widget details
 */
export async function getWidget(widgetId: string): Promise<Widget> {
  const data = await httpClient<{ success: boolean; widget: Widget }>(
    `/api/v1/widgets/${widgetId}`,
    {
      method: 'GET',
    }
  )
  return data.widget
}

/**
 * Update widget
 */
export async function updateWidget(widgetId: string, request: UpdateWidgetRequest): Promise<void> {
  console.log('🔧 widgetsApi.updateWidget called:', {
    widgetId,
    request,
    allowedDomains: request.config?.allowedDomains,
  })

  await httpClient<{ success: boolean }>(`/api/v1/widgets/${widgetId}`, {
    method: 'PUT',
    body: JSON.stringify(request),
  })
}

/**
 * Delete widget
 */
export async function deleteWidget(widgetId: string): Promise<void> {
  await httpClient<{ success: boolean }>(`/api/v1/widgets/${widgetId}`, {
    method: 'DELETE',
  })
}

/**
 * Get embed code for widget
 */
export async function getEmbedCode(widgetId: string): Promise<EmbedCodeResponse> {
  return await httpClient<EmbedCodeResponse>(`/api/v1/widgets/${widgetId}/embed`, {
    method: 'GET',
  })
}

/**
 * Get widget statistics
 */
export async function getWidgetStats(widgetId: string): Promise<{
  active_sessions: number
  total_messages: number
}> {
  const data = await httpClient<{ success: boolean; stats: any }>(
    `/api/v1/widgets/${widgetId}/stats`,
    {
      method: 'GET',
    }
  )
  return data.stats
}

export interface SendWidgetMessageOptions {
  chatId?: number
  fileIds?: number[]
  apiUrl?: string
  headers?: Record<string, string>
  onChunk?: (chunk: string) => void | Promise<void>
  onStatus?: (payload: { status: string; message?: string; metadata?: any }) => void
}

export interface SendWidgetMessageResult {
  success: boolean
  messageId: number
  chatId: number
  metadata?: unknown
  remainingUploads?: number | null
  text: string
}

/**
 * Send message to widget (public endpoint - no auth required, SSE streaming)
 */
export async function sendWidgetMessage(
  widgetId: string,
  text: string,
  sessionId: string,
  options: SendWidgetMessageOptions = {}
): Promise<SendWidgetMessageResult> {
  const { chatId, fileIds, apiUrl: apiUrlOverride, headers: extraHeaders, onStatus } = options

  const config = useConfigStore()
  const apiUrl = apiUrlOverride ?? config.apiBaseUrl

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'text/event-stream',
    'X-Widget-Session': sessionId,
    ...(typeof window !== 'undefined' && window.location?.host
      ? { 'X-Widget-Host': window.location.host }
      : {}),
    ...(extraHeaders ?? {}),
  }

  const payload: Record<string, unknown> = {
    sessionId,
    text,
  }

  if (typeof chatId === 'number') {
    payload.chatId = chatId
  }

  if (fileIds && fileIds.length > 0) {
    payload.files = fileIds
  }

  // Include credentials when in test mode (X-Widget-Test-Mode header is present)
  const isTestMode = extraHeaders?.['X-Widget-Test-Mode'] === 'true'

  const response = await fetch(`${apiUrl}/api/v1/widget/${widgetId}/message`, {
    method: 'POST',
    headers,
    body: JSON.stringify(payload),
    credentials: isTestMode ? 'include' : 'omit',
  })

  if (!response.ok) {
    const error = await response.json().catch(() => ({ error: 'Unknown error' }))
    throw new Error(error.error || `HTTP ${response.status}`)
  }

  const decoder = new TextDecoder()
  let buffer = ''
  let messageId: number | null = null
  let finalChatId: number | null = null
  let metadata: unknown = null
  let remainingUploads: number | null = null
  let completed = false
  let aggregatedText = ''

  const emitChunk = async (chunk: string) => {
    if (!chunk) {
      return
    }
    aggregatedText += chunk
    if (options.onChunk) {
      await options.onChunk(chunk)
    }
  }

  const processEvent = async (eventChunk: string) => {
    const lines = eventChunk.split('\n')
    const dataLines = lines
      .filter((line) => line.startsWith('data:'))
      .map((line) => line.slice(5).trim())
      .filter(Boolean)

    if (dataLines.length === 0) {
      return
    }

    const jsonStr = dataLines.join('')

    let data: any
    try {
      data = JSON.parse(jsonStr)
    } catch (error) {
      console.error('Failed to parse SSE data:', error, jsonStr)
      return
    }

    const status = data.status ?? 'data'

    if (status === 'data' && typeof data.chunk === 'string') {
      await emitChunk(data.chunk)
    } else if (status === 'error') {
      throw new Error(data.error || 'Streaming error')
    } else if (status === 'complete') {
      messageId = typeof data.messageId === 'number' ? data.messageId : messageId
      finalChatId = typeof data.chatId === 'number' ? data.chatId : finalChatId
      metadata = data.metadata ?? metadata
      if (typeof data.remainingUploads === 'number') {
        remainingUploads = data.remainingUploads
      }
      if (onStatus) {
        onStatus(data)
      }
      completed = true
    } else {
      if (onStatus) {
        onStatus(data)
      }
    }
  }

  const handleBufferedEvents = async (eventText: string) => {
    const events = eventText.split('\n\n')
    for (const eventChunk of events) {
      if (!eventChunk.trim()) continue
      await processEvent(eventChunk)
      if (completed) {
        break
      }
    }
  }

  const reader =
    response.body && typeof response.body.getReader === 'function'
      ? response.body.getReader()
      : null

  if (reader) {
    try {
      while (true) {
        const { done, value } = await reader.read()
        if (done) {
          break
        }

        buffer += decoder.decode(value, { stream: true })
        const events = buffer.split('\n\n')
        buffer = events.pop() ?? ''

        for (const eventChunk of events) {
          await processEvent(eventChunk)
          if (completed) {
            break
          }
        }

        if (completed) {
          break
        }
      }

      if (!completed && buffer.trim() !== '') {
        await processEvent(buffer)
      }
    } finally {
      try {
        await reader.cancel()
      } catch {
        // ignore cancellation errors
      }
    }
  } else {
    const text = await response.text()
    await handleBufferedEvents(text)
  }

  if (messageId === null) {
    console.warn('[widgetsApi] Stream finished without messageId')
    messageId = -1
  }

  if (finalChatId === null) {
    finalChatId = typeof chatId === 'number' ? chatId : -1
  }

  return {
    success: true,
    messageId,
    chatId: finalChatId,
    metadata,
    remainingUploads,
    text: aggregatedText,
  }
}

/**
 * Upload file to widget (public endpoint - no auth required)
 */
export async function uploadWidgetFile(
  widgetId: string,
  sessionId: string,
  file: File,
  options?: { apiUrl?: string; headers?: Record<string, string> }
): Promise<{
  success: boolean
  file: {
    id: number
    filename: string
    size: number
    extracted_text_length: number
    chunks_created?: number
  }
  remainingUploads?: number
}> {
  const config = useConfigStore()
  const baseUrl = options?.apiUrl ?? config.apiBaseUrl

  const formData = new FormData()
  formData.append('file', file)

  const headers: Record<string, string> = {
    'X-Widget-Session': sessionId,
    ...(options?.headers ?? {}),
  }

  if (typeof window !== 'undefined' && window.location?.host) {
    headers['X-Widget-Host'] = window.location.host
  }

  // Include credentials when in test mode (X-Widget-Test-Mode header is present)
  const isTestMode = options?.headers?.['X-Widget-Test-Mode'] === 'true'

  console.log('🌐 Widget file upload request:', {
    url: `${baseUrl}/api/v1/widget/${widgetId}/upload`,
    method: 'POST',
    bodyPreview: 'FormData with file',
    isTestMode,
  })

  const response = await fetch(`${baseUrl}/api/v1/widget/${widgetId}/upload`, {
    method: 'POST',
    headers,
    body: formData,
    credentials: isTestMode ? 'include' : 'omit',
  })

  console.log('🌐 Widget file upload response:', {
    url: `/api/v1/widget/${widgetId}/upload`,
    status: response.status,
    statusText: response.statusText,
    ok: response.ok,
  })

  if (!response.ok) {
    const error = await response.json().catch(() => ({ error: 'Upload failed' }))
    throw new Error(error.error || `HTTP ${response.status}: ${response.statusText}`)
  }

  return await response.json()
}

/**
 * Send a message in the widget setup interview
 */
export async function sendSetupMessage(
  widgetId: string,
  text: string,
  chatId: number | null
): Promise<{
  success: boolean
  chatId: number
  messageId: number
  text: string
  progress: number
}> {
  const data = await httpClient<{
    success: boolean
    chatId: number
    messageId: number
    text: string
    progress: number
  }>(`/api/v1/widgets/${widgetId}/setup-chat`, {
    method: 'POST',
    body: JSON.stringify({ text, chatId }),
  })
  return data
}

/**
 * Generate and save a custom prompt from the setup interview
 */
export async function generateWidgetPrompt(
  widgetId: string,
  generatedPrompt: string,
  chatId: number | null
): Promise<{
  success: boolean
  promptTopic: string
  promptId: number
}> {
  const data = await httpClient<{
    success: boolean
    promptTopic: string
    promptId: number
  }>(`/api/v1/widgets/${widgetId}/generate-prompt`, {
    method: 'POST',
    body: JSON.stringify({ generatedPrompt, chatId }),
  })
  return data
}

/**
 * Upload widget button icon
 */
export async function uploadWidgetIcon(
  widgetId: string,
  file: File
): Promise<{
  success: boolean
  iconUrl: string
  filename: string
}> {
  const config = useConfigStore()
  const apiUrl = config.apiBaseUrl

  const formData = new FormData()
  formData.append('icon', file)

  const response = await fetch(`${apiUrl}/api/v1/widgets/${widgetId}/upload-icon`, {
    method: 'POST',
    credentials: 'include', // Use cookies for auth
    body: formData,
  })

  if (!response.ok) {
    const error = await response.json().catch(() => ({ error: 'Upload failed' }))
    throw new Error(error.error || `HTTP ${response.status}`)
  }

  return await response.json()
}
