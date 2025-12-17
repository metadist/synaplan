import { describe, it, expect, vi, beforeEach } from 'vitest'
import { inboundEmailHandlersApi } from '@/services/api/inboundEmailHandlersApi'
import { httpClient } from '@/services/api/httpClient'

vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn(),
}))

describe('inboundEmailHandlersApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('list', () => {
    it('fetches list of handlers', async () => {
      const mockBackendHandlers = [
        {
          id: 1,
          name: 'Handler 1',
          mailServer: 'imap.test.com',
          port: 993,
          protocol: 'IMAP' as const,
          security: 'SSL/TLS' as const,
          username: 'test@test.com',
          password: '••••••••',
          checkInterval: 10,
          deleteAfter: false,
          status: 'active' as const,
          departments: [],
          smtpConfig: {
            server: 'smtp.test.com',
            port: 587,
            username: 'test@test.com',
            password: '••••••••',
            security: 'STARTTLS' as const,
          },
          emailFilter: {
            mode: 'new' as const,
            fromDate: null,
            toDate: null,
          },
          lastChecked: null,
          created: '20251217120000',
          updated: '20251217120000',
        },
      ]

      vi.mocked(httpClient).mockResolvedValue({
        success: true,
        handlers: mockBackendHandlers,
      })

      const result = await inboundEmailHandlersApi.list()

      expect(httpClient).toHaveBeenCalledWith('/api/v1/inbound-email-handlers', {
        method: 'GET',
      })
      expect(result).toHaveLength(1)
      expect(result[0].name).toBe('Handler 1')
    })

    it('handles API errors', async () => {
      vi.mocked(httpClient).mockRejectedValue(new Error('Network error'))

      await expect(inboundEmailHandlersApi.list()).rejects.toThrow('Network error')
    })
  })

  describe('create', () => {
    it('creates a new handler', async () => {
      const newHandler = {
        name: 'Test Handler',
        mailServer: 'imap.test.com',
        port: 993,
        protocol: 'IMAP' as const,
        security: 'SSL/TLS' as const,
        username: 'test@test.com',
        password: 'test-password',
        checkInterval: 10,
        deleteAfter: false,
        departments: [{ id: '1', email: 'support@test.com', rules: 'Support', isDefault: true }],
        smtpServer: 'smtp.test.com',
        smtpPort: 587,
        smtpUsername: 'test@test.com',
        smtpPassword: 'test-smtp-password',
        smtpSecurity: 'STARTTLS' as const,
        emailFilterMode: 'new' as const,
      }

      const mockBackendResponse = {
        id: 123,
        ...newHandler,
        status: 'inactive' as const,
        smtpConfig: {
          server: newHandler.smtpServer,
          port: newHandler.smtpPort,
          username: newHandler.smtpUsername,
          password: '••••••••',
          security: newHandler.smtpSecurity,
        },
        emailFilter: {
          mode: newHandler.emailFilterMode,
          fromDate: null,
          toDate: null,
        },
        lastChecked: null,
        created: '20251217120000',
        updated: '20251217120000',
      }

      vi.mocked(httpClient).mockResolvedValue({
        success: true,
        handler: mockBackendResponse,
      })

      const result = await inboundEmailHandlersApi.create(newHandler)

      expect(httpClient).toHaveBeenCalledWith('/api/v1/inbound-email-handlers', {
        method: 'POST',
        body: JSON.stringify(newHandler),
      })
      expect(result.name).toBe('Test Handler')
    })
  })

  describe('update', () => {
    it('updates an existing handler', async () => {
      const handlerId = '123'
      const updates = {
        name: 'Updated Handler',
        status: 'active' as const,
      }

      const mockBackendResponse = {
        id: 123,
        name: 'Updated Handler',
        mailServer: 'imap.test.com',
        port: 993,
        protocol: 'IMAP' as const,
        security: 'SSL/TLS' as const,
        username: 'test@test.com',
        password: '••••••••',
        checkInterval: 10,
        deleteAfter: false,
        status: 'active' as const,
        departments: [],
        smtpConfig: {
          server: 'smtp.test.com',
          port: 587,
          username: 'test@test.com',
          password: '••••••••',
          security: 'STARTTLS' as const,
        },
        emailFilter: {
          mode: 'new' as const,
          fromDate: null,
          toDate: null,
        },
        lastChecked: null,
        created: '20251217120000',
        updated: '20251217120000',
      }

      vi.mocked(httpClient).mockResolvedValue({
        success: true,
        handler: mockBackendResponse,
      })

      const result = await inboundEmailHandlersApi.update(handlerId, updates)

      expect(httpClient).toHaveBeenCalledWith(`/api/v1/inbound-email-handlers/${handlerId}`, {
        method: 'PUT',
        body: JSON.stringify(updates),
      })
      expect(result.name).toBe('Updated Handler')
    })
  })

  describe('delete', () => {
    it('deletes a handler', async () => {
      const handlerId = '123'

      vi.mocked(httpClient).mockResolvedValue({
        success: true,
        message: 'Handler deleted',
      })

      await inboundEmailHandlersApi.delete(handlerId)

      expect(httpClient).toHaveBeenCalledWith(`/api/v1/inbound-email-handlers/${handlerId}`, {
        method: 'DELETE',
      })
    })
  })

  describe('testConnection', () => {
    it('tests IMAP connection', async () => {
      const handlerId = '123'

      vi.mocked(httpClient).mockResolvedValue({
        success: true,
        message: 'Connection successful',
      })

      const result = await inboundEmailHandlersApi.testConnection(handlerId)

      expect(httpClient).toHaveBeenCalledWith(`/api/v1/inbound-email-handlers/${handlerId}/test`, {
        method: 'POST',
      })
      expect(result.success).toBe(true)
      expect(result.message).toBe('Connection successful')
    })

    it('returns error on connection failure', async () => {
      const handlerId = '123'

      vi.mocked(httpClient).mockResolvedValue({
        success: false,
        message: 'Connection failed: Invalid credentials',
      })

      const result = await inboundEmailHandlersApi.testConnection(handlerId)

      expect(result.success).toBe(false)
      expect(result.message).toContain('Invalid credentials')
    })
  })
})
