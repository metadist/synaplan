/**
 * Inbound Email Handlers API
 *
 * API for managing email handlers (IMAP/POP3 configuration for email routing)
 */

import { httpClient } from './httpClient'
import { PostApiInboundEmailHandlersTestConnectionPreviewResponseSchema } from '@/generated/api-schemas'

/** Backend masked secret placeholder (must match ToolsView and API). */
export const MASKED_MAIL_PASSWORD_PLACEHOLDER = '••••••••' as const

// Backend Response Types
export interface BackendMailHandler {
  id: number
  name: string
  mailServer: string
  port: number
  protocol: 'IMAP' | 'POP3'
  security: 'SSL/TLS' | 'STARTTLS' | 'None'
  username: string
  password: string // Always '••••••••' from backend
  checkInterval: number
  deleteAfter: boolean
  status: 'active' | 'inactive' | 'error'
  departments: Department[]
  smtpConfig?: {
    server: string
    port: number
    username: string
    password: string // Always '••••••••' from backend
    security: 'SSL/TLS' | 'STARTTLS' | 'None'
  } | null
  emailFilter?: {
    mode: 'new' | 'historical'
    fromDate: string | null
  } | null
  lastChecked: string | null // YmdHis format
  created: string // YmdHis format
  updated: string // YmdHis format
}

export interface Department {
  id: string
  email: string
  rules: string
  isDefault: boolean
}

export interface MailConfig {
  mailServer: string
  port: number
  protocol: 'IMAP' | 'POP3'
  security: 'SSL/TLS' | 'STARTTLS' | 'None'
  username: string
  password: string
  checkInterval: number
  deleteAfter: boolean
}

export interface SmtpConfig {
  smtpServer: string
  smtpPort: number
  smtpSecurity: 'STARTTLS' | 'SSL/TLS' | 'None'
  smtpUsername: string
  smtpPassword: string
}

export interface EmailFilterConfig {
  mode: 'new' | 'historical'
  fromDate?: string | null
}

export interface SavedMailHandler {
  id: string
  name: string
  config: MailConfig
  departments: Department[]
  status: 'active' | 'inactive' | 'error'
  smtpConfig?: SmtpConfig
  emailFilter?: EmailFilterConfig
  lastTested?: Date
  createdAt: Date
  updatedAt: Date
}

export interface CreateHandlerRequest {
  name: string
  mailServer: string
  port: number
  protocol: 'IMAP' | 'POP3'
  security: 'SSL/TLS' | 'STARTTLS' | 'None'
  username: string
  password: string
  checkInterval: number
  deleteAfter: boolean
  departments: Department[]
  // SMTP credentials for forwarding (REQUIRED)
  smtpServer: string
  smtpPort: number
  smtpSecurity: 'STARTTLS' | 'SSL/TLS' | 'None'
  smtpUsername: string
  smtpPassword: string
  // Email filter settings
  emailFilterMode: 'new' | 'historical'
  emailFilterFromDate?: string | null
}

export interface TestMailboxConnectionBody {
  mailServer: string
  port: number
  protocol: 'IMAP' | 'POP3'
  security: 'SSL/TLS' | 'STARTTLS' | 'None'
  username: string
  /** Omit when reusing saved password via handlerId */
  password?: string
  /**
   * When password is omitted/masked, load the stored password for this
   * handler. Accepts string (from form inputs) or number (from API
   * responses); a non-finite or non-positive value is treated as "not
   * provided" by `testMailboxConnection`.
   */
  handlerId?: string | number
}

/**
 * Possible activity log events emitted by the mail handler runner.
 * Mirrors `MailHandlerLogService::EVENT_*` constants on the backend.
 */
export type MailHandlerLogEvent =
  | 'check'
  | 'connect_failed'
  | 'forwarded'
  | 'discarded'
  | 'no_route'
  | 'no_smtp'
  | 'forward_failed'
  | 'process_error'

export type MailHandlerLogStatus = 'success' | 'warning' | 'error'

export interface MailHandlerLogEntry {
  id: number
  /** Unix epoch seconds when the event was recorded. */
  timestamp: number
  event: MailHandlerLogEvent
  status: MailHandlerLogStatus
  /** Free-text error / explanation. Empty for success entries. */
  error: string
  /**
   * Free-form metadata captured for this event (subject, from, routed_to,
   * matched, criteria, message_number, ...). Shape depends on `event`.
   */
  details: Record<string, unknown>
}

export interface UpdateHandlerRequest {
  name?: string
  mailServer?: string
  port?: number
  protocol?: 'IMAP' | 'POP3'
  security?: 'SSL/TLS' | 'STARTTLS' | 'None'
  username?: string
  password?: string
  checkInterval?: number
  deleteAfter?: boolean
  status?: 'active' | 'inactive' | 'error'
  departments?: Department[]
  // SMTP credentials for forwarding (optional for updates)
  smtpServer?: string
  smtpPort?: number
  smtpSecurity?: 'STARTTLS' | 'SSL/TLS' | 'None'
  smtpUsername?: string
  smtpPassword?: string
  // Email filter settings
  emailFilterMode?: 'new' | 'historical'
  emailFilterFromDate?: string | null
}

/**
 * Convert backend format to frontend format
 */
function convertBackendToFrontend(backend: BackendMailHandler): SavedMailHandler {
  return {
    id: backend.id.toString(),
    name: backend.name,
    config: {
      mailServer: backend.mailServer,
      port: backend.port,
      protocol: backend.protocol,
      security: backend.security,
      username: backend.username,
      password: backend.password, // Already masked
      checkInterval: backend.checkInterval,
      deleteAfter: backend.deleteAfter,
    },
    departments: backend.departments.map((dept, index) => ({
      id: dept.id || index.toString(),
      email: dept.email,
      rules: dept.rules,
      isDefault: dept.isDefault,
    })),
    status: backend.status,
    smtpConfig: backend.smtpConfig
      ? {
          smtpServer: backend.smtpConfig.server,
          smtpPort: backend.smtpConfig.port,
          smtpSecurity: backend.smtpConfig.security,
          smtpUsername: backend.smtpConfig.username,
          smtpPassword: backend.smtpConfig.password, // Already masked
        }
      : undefined,
    emailFilter: backend.emailFilter
      ? {
          mode: backend.emailFilter.mode,
          fromDate: backend.emailFilter.fromDate,
        }
      : undefined,
    lastTested: backend.lastChecked ? parseDate(backend.lastChecked) : undefined,
    createdAt: parseDate(backend.created),
    updatedAt: parseDate(backend.updated),
  }
}

/**
 * Parse YmdHis date string to Date
 */
function parseDate(dateStr: string): Date {
  // Format: YmdHis (e.g., 20250108143000)
  if (dateStr.length === 14) {
    const year = parseInt(dateStr.substring(0, 4))
    const month = parseInt(dateStr.substring(4, 6)) - 1
    const day = parseInt(dateStr.substring(6, 8))
    const hour = parseInt(dateStr.substring(8, 10))
    const minute = parseInt(dateStr.substring(10, 12))
    const second = parseInt(dateStr.substring(12, 14))
    return new Date(year, month, day, hour, minute, second)
  }
  return new Date()
}

export const inboundEmailHandlersApi = {
  /**
   * List all handlers for current user
   */
  async list(): Promise<SavedMailHandler[]> {
    const data = await httpClient<{ success: boolean; handlers: BackendMailHandler[] }>(
      '/api/v1/inbound-email-handlers',
      { method: 'GET' }
    )
    return data.handlers.map(convertBackendToFrontend)
  },

  /**
   * Get single handler by ID
   */
  async get(id: string): Promise<SavedMailHandler> {
    const data = await httpClient<{ success: boolean; handler: BackendMailHandler }>(
      `/api/v1/inbound-email-handlers/${id}`,
      { method: 'GET' }
    )
    return convertBackendToFrontend(data.handler)
  },

  /**
   * Create new handler
   */
  async create(request: CreateHandlerRequest): Promise<SavedMailHandler> {
    const data = await httpClient<{ success: boolean; handler: BackendMailHandler }>(
      '/api/v1/inbound-email-handlers',
      {
        method: 'POST',
        body: JSON.stringify(request),
      }
    )
    return convertBackendToFrontend(data.handler)
  },

  /**
   * Update handler
   */
  async update(id: string, request: UpdateHandlerRequest): Promise<SavedMailHandler> {
    const data = await httpClient<{ success: boolean; handler: BackendMailHandler }>(
      `/api/v1/inbound-email-handlers/${id}`,
      {
        method: 'PUT',
        body: JSON.stringify(request),
      }
    )
    return convertBackendToFrontend(data.handler)
  },

  /**
   * Delete handler
   */
  async delete(id: string): Promise<void> {
    await httpClient<{ success: boolean; message: string }>(
      `/api/v1/inbound-email-handlers/${id}`,
      { method: 'DELETE' }
    )
  },

  /**
   * Test mailbox (IMAP/POP3) with unsaved form values (no handler ID required).
   */
  async testMailboxConnection(
    body: TestMailboxConnectionBody
  ): Promise<{ success: boolean; message: string }> {
    const payload: Record<string, unknown> = {
      mailServer: body.mailServer,
      port: body.port,
      protocol: body.protocol,
      security: body.security,
      username: body.username,
    }

    const pwd = body.password ?? ''
    // Parse handlerId defensively: the caller may pass a string from a
    // form, a number, or `undefined`. Number(undefined)/Number('') yield
    // NaN, which JSON-serializes to `null` and the backend then casts to
    // 0 — silently looking up handler #0 and 404-ing. Coerce only when
    // we actually have a finite positive integer.
    const parsedHandlerId =
      body.handlerId === undefined || body.handlerId === ''
        ? null
        : (() => {
            const n =
              typeof body.handlerId === 'number'
                ? body.handlerId
                : parseInt(String(body.handlerId), 10)
            return Number.isFinite(n) && n > 0 ? n : null
          })()
    const useStoredPassword =
      parsedHandlerId !== null && (pwd === '' || pwd === MASKED_MAIL_PASSWORD_PLACEHOLDER)

    if (useStoredPassword) {
      payload.handlerId = parsedHandlerId
    } else if (pwd !== '') {
      payload.password = pwd
    }

    const data = await httpClient('/api/v1/inbound-email-handlers/test-connection', {
      method: 'POST',
      body: JSON.stringify(payload),
      schema: PostApiInboundEmailHandlersTestConnectionPreviewResponseSchema,
    })

    return {
      success: data.success === true,
      message: typeof data.message === 'string' ? data.message : '',
    }
  },

  /**
   * Test IMAP connection
   */
  async testConnection(id: string): Promise<{ success: boolean; message: string }> {
    return httpClient<{ success: boolean; message: string }>(
      `/api/v1/inbound-email-handlers/${id}/test`,
      { method: 'POST' }
    )
  },

  /**
   * Bulk update handler status
   */
  async bulkUpdateStatus(
    handlerIds: string[],
    status: 'active' | 'inactive'
  ): Promise<{ success: boolean; updated: number }> {
    return httpClient<{ success: boolean; updated: number }>(
      '/api/v1/inbound-email-handlers/bulk-update-status',
      {
        method: 'POST',
        body: JSON.stringify({ handlerIds, status }),
      }
    )
  },

  /**
   * Bulk delete handlers
   */
  async bulkDelete(handlerIds: string[]): Promise<{ success: boolean; deleted: number }> {
    return httpClient<{ success: boolean; deleted: number }>(
      '/api/v1/inbound-email-handlers/bulk-delete',
      {
        method: 'POST',
        body: JSON.stringify({ handlerIds }),
      }
    )
  },

  /**
   * Recent activity log for a handler (newest first, capped at 10 entries
   * by the backend). Useful for diagnosing "marked seen but never forwarded"
   * cases — see `MailHandlerLogService` on the backend.
   */
  async getLogs(id: string): Promise<MailHandlerLogEntry[]> {
    const data = await httpClient<{ success: boolean; logs: MailHandlerLogEntry[] }>(
      `/api/v1/inbound-email-handlers/${id}/logs`,
      { method: 'GET' }
    )
    return Array.isArray(data.logs) ? data.logs : []
  },
}
