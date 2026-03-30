/**
 * SSE / streaming payloads for chat API (narrowed fields used by ChatView and widgets).
 */
export interface StreamLinkItem {
  title?: string
  url: string
  description?: string
}

export interface StreamGeneratedFile {
  id: number
  filename: string
  path: string
  size: number
  type: string
  mime: string
}

export interface StreamSearchResult {
  query?: string
  title?: string
  url?: string
  description?: string
  published?: string
  source?: string
  thumbnail?: string
  [key: string]: unknown
}

export interface StreamSuggestedModels {
  quick?: string[]
  medium?: string[]
  large?: string[]
}

export interface StreamMemoryRow {
  id: number
  category?: string
  key?: string
  value?: string
  source?: string
  messageId?: number | null
  created?: number
  updated?: number
}

export interface StreamFeedbackRow {
  id: number
  type?: string
  value?: string
}

export interface StreamEventMetadata {
  id?: number
  category?: string
  key?: string
  value?: string
  source?: string
  messageId?: number | null
  created?: number
  updated?: number
  action?: string
  /** Model routing during classify / generate */
  provider?: string
  model_name?: string
  language?: string
  memories?: StreamMemoryRow[]
  feedbacks?: StreamFeedbackRow[]
  [key: string]: unknown
}

/** Normalized chat stream event from /api/v1/messages/stream */
export interface StreamUpdatePayload {
  status?: string
  chunk?: string
  error?: string
  message?: string
  messageId?: number
  chatId?: number
  content?: string
  url?: string
  type?: string
  truncated?: boolean
  metadata?: StreamEventMetadata
  links?: StreamLinkItem[]
  generatedFile?: StreamGeneratedFile
  searchResults?: StreamSearchResult[]
  memoryIds?: number[]
  feedbackIds?: number[]
  provider?: string
  model?: string
  model_id?: number | null
  topic?: string
  originalTopic?: string | null
  originalMediaType?: string | null
  limit_type?: string
  action_type?: string
  used?: number
  limit?: number
  remaining?: number
  reset_at?: number | null
  user_level?: string
  phone_verified?: boolean
  install_command?: string
  suggested_models?: StreamSuggestedModels
  [key: string]: unknown
}
