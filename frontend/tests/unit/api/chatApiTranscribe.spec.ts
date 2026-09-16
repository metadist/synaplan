import { describe, it, expect, vi, beforeEach } from 'vitest'

const httpClient = vi.fn().mockResolvedValue({
  success: true,
  filename: 'recording.webm',
  text: 'hello',
})

vi.mock('@/services/api/httpClient', () => ({
  httpClient: (...args: unknown[]) => httpClient(...args),
  getApiBaseUrl: () => 'http://localhost',
  awaitAuthMutation: async () => undefined,
  isDefinitiveAuthRejection: () => false,
}))

import { chatApi } from '@/services/api/chatApi'

describe('chatApi.transcribeAudio', () => {
  beforeEach(() => {
    httpClient.mockClear()
  })

  it('sends purpose=dictation so the recording is not kept as a Source', async () => {
    await chatApi.transcribeAudio(new Blob(['x'], { type: 'audio/webm' }))

    expect(httpClient).toHaveBeenCalledTimes(1)
    const [url, options] = httpClient.mock.calls[0] as [string, { body: FormData }]
    expect(url).toBe('/api/v1/messages/upload-file')
    expect(options.body.get('purpose')).toBe('dictation')
    expect(options.body.get('file')).toBeInstanceOf(Blob)
  })
})
