/**
 * Inbound Email Handlers API
 * 
 * API for managing email handlers (IMAP/POP3 configuration for email routing)
 */

import { httpClient } from './httpClient'

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

export interface SavedMailHandler {
  id: string
  name: string
  config: MailConfig
  departments: Department[]
  status: 'active' | 'inactive' | 'error'
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
  emailFilterToDate?: string | null
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
  emailFilterToDate?: string | null
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
      deleteAfter: backend.deleteAfter
    },
    departments: backend.departments.map((dept, index) => ({
      id: dept.id || index.toString(),
      email: dept.email,
      rules: dept.rules,
      isDefault: dept.isDefault
    })),
    status: backend.status,
    lastTested: backend.lastChecked ? parseDate(backend.lastChecked) : undefined,
    createdAt: parseDate(backend.created),
    updatedAt: parseDate(backend.updated)
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
        body: JSON.stringify(request)
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
        body: JSON.stringify(request)
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
   * Test IMAP connection
   */
  async testConnection(id: string): Promise<{ success: boolean; message: string }> {
    return httpClient<{ success: boolean; message: string }>(
      `/api/v1/inbound-email-handlers/${id}/test`,
      { method: 'POST' }
    )
  }
}

