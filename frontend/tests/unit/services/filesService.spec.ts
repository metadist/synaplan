import { describe, it, expect, vi, beforeEach } from 'vitest'
import { checkUpload, uploadFiles, UploadBlockedError } from '@/services/filesService'
import { httpClient } from '@/services/api/httpClient'

vi.mock('@/services/api/httpClient', () => ({
  httpClient: vi.fn(),
  getApiBaseUrl: vi.fn(() => 'http://localhost:8000'),
  refreshAccessToken: vi.fn(),
}))

const mockedHttpClient = vi.mocked(httpClient)

describe('filesService', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  describe('checkUpload (pre-flight)', () => {
    it('POSTs filename + size to /api/v1/files/check-upload', async () => {
      mockedHttpClient.mockResolvedValueOnce({
        allowed: true,
        max_file_size: 128 * 1024 * 1024,
        allowed_extensions: ['pdf', 'txt'],
        remaining: 5 * 1024 * 1024,
      })

      const result = await checkUpload('doc.pdf', 1024, 'application/pdf')

      expect(httpClient).toHaveBeenCalledWith('/api/v1/files/check-upload', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ filename: 'doc.pdf', size: 1024, mime: 'application/pdf' }),
      })
      expect(result.allowed).toBe(true)
      expect(result.max_file_size).toBe(128 * 1024 * 1024)
    })

    it('returns the rejection payload unchanged', async () => {
      mockedHttpClient.mockResolvedValueOnce({
        allowed: false,
        reason: 'storage_exceeded',
        message: 'Storage limit exceeded. You have 1 MB remaining, but the file is 5 MB.',
        max_file_size: 128 * 1024 * 1024,
        allowed_extensions: ['pdf'],
        remaining: 1024 * 1024,
      })

      const result = await checkUpload('big.pdf', 5 * 1024 * 1024)

      expect(result.allowed).toBe(false)
      expect(result.reason).toBe('storage_exceeded')
      expect(result.message).toContain('Storage limit exceeded')
    })
  })

  describe('uploadFiles pre-flight integration', () => {
    it('throws UploadBlockedError BEFORE attempting the actual upload when quota is exceeded', async () => {
      mockedHttpClient.mockResolvedValueOnce({
        allowed: false,
        reason: 'storage_exceeded',
        message: 'Storage limit exceeded.',
        max_file_size: 128 * 1024 * 1024,
        allowed_extensions: ['pdf'],
        remaining: 0,
      })

      const file = new File(['hello'], 'doc.pdf', { type: 'application/pdf' })

      await expect(
        uploadFiles({ files: [file], processLevel: 'vectorize' })
      ).rejects.toBeInstanceOf(UploadBlockedError)

      // The check-upload call happened, but the actual /upload call did NOT
      expect(httpClient).toHaveBeenCalledTimes(1)
      expect(httpClient).toHaveBeenCalledWith(
        '/api/v1/files/check-upload',
        expect.objectContaining({ method: 'POST' })
      )
    })

    it('propagates reason and filename on UploadBlockedError', async () => {
      mockedHttpClient.mockResolvedValueOnce({
        allowed: false,
        reason: 'file_too_large',
        message: 'File too large. Maximum size is 128 MB.',
        max_file_size: 128 * 1024 * 1024,
        allowed_extensions: ['pdf'],
        remaining: 50 * 1024 * 1024,
      })

      const file = new File(['x'], 'huge.pdf', { type: 'application/pdf' })

      try {
        await uploadFiles({ files: [file], processLevel: 'vectorize' })
        expect.unreachable('Expected UploadBlockedError')
      } catch (err) {
        expect(err).toBeInstanceOf(UploadBlockedError)
        const blocked = err as UploadBlockedError
        expect(blocked.reason).toBe('file_too_large')
        expect(blocked.filename).toBe('huge.pdf')
        expect(blocked.check.max_file_size).toBe(128 * 1024 * 1024)
      }
    })

    it('proceeds to the upload call when the pre-flight allows it', async () => {
      mockedHttpClient
        .mockResolvedValueOnce({
          allowed: true,
          max_file_size: 128 * 1024 * 1024,
          allowed_extensions: ['pdf'],
          remaining: 100 * 1024 * 1024,
        })
        .mockResolvedValueOnce({
          success: true,
          files: [
            {
              id: 1,
              filename: 'ok.pdf',
              size: 5,
              mime: 'application/pdf',
              path: '/tmp/ok.pdf',
              group_key: 'TEST',
              processing_time_ms: 5,
            },
          ],
          errors: [],
          total_time_ms: 5,
          process_level: 'vectorize',
        })

      const file = new File(['hello'], 'ok.pdf', { type: 'application/pdf' })

      const result = await uploadFiles({
        files: [file],
        groupKey: 'TEST',
        processLevel: 'vectorize',
      })

      expect(result.success).toBe(true)
      // First call = check-upload, second call = actual upload (no onProgress so uses httpClient)
      expect(httpClient).toHaveBeenCalledTimes(2)
      expect(httpClient).toHaveBeenNthCalledWith(
        1,
        '/api/v1/files/check-upload',
        expect.objectContaining({ method: 'POST' })
      )
      expect(httpClient).toHaveBeenNthCalledWith(
        2,
        '/api/v1/files/upload',
        expect.objectContaining({ method: 'POST' })
      )
    })
  })
})
