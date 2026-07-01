import { describe, it, expect } from 'vitest'
import { applyMediaJobUpdateToMessage } from '@/utils/messageMapper'
import type { Message } from '@/stores/history'

/**
 * Sprint C: the shared helper that applies a realtime/poll media-job update to a
 * loaded message in place — patches `mediaJob` and appends the generated media
 * part on terminal success. Used by both the `mediaJobs` store (push) and
 * ChatView's completion handler so the two paths can never diverge.
 */
function makeMessage(overrides: Partial<Message> = {}): Message {
  return {
    id: 'local-1',
    role: 'assistant',
    parts: [{ type: 'text', content: '' }],
    timestamp: new Date(),
    backendMessageId: 305,
    mediaJob: { jobId: 'job-1', type: 'video', state: 'running' },
    ...overrides,
  } as Message
}

describe('applyMediaJobUpdateToMessage', () => {
  it('flips the job to done and appends the generated media part', () => {
    const m = makeMessage()
    applyMediaJobUpdateToMessage(m, {
      job_id: 'job-1',
      message_id: 305,
      type: 'video',
      state: 'done',
      file: { url: '/api/v1/files/uploads/x.mp4', type: 'video' },
    })

    expect(m.mediaJob?.state).toBe('done')
    expect(m.parts.some((p) => p.type === 'video')).toBe(true)
  })

  it('is idempotent — a repeated done update does not duplicate the media part', () => {
    const m = makeMessage()
    const update = {
      job_id: 'job-1',
      message_id: 305,
      type: 'video',
      state: 'done',
      file: { url: '/api/v1/files/uploads/x.mp4', type: 'video' },
    }
    applyMediaJobUpdateToMessage(m, update)
    applyMediaJobUpdateToMessage(m, update)

    expect(m.parts.filter((p) => p.type === 'video')).toHaveLength(1)
  })

  it('appends an image part with alt text for image jobs', () => {
    const m = makeMessage({ mediaJob: { jobId: 'j', type: 'image', state: 'running' } })
    applyMediaJobUpdateToMessage(m, {
      job_id: 'j',
      message_id: 305,
      type: 'image',
      state: 'done',
      file: { url: '/api/v1/files/uploads/x.png', type: 'image' },
    })

    const imagePart = m.parts.find((p) => p.type === 'image')
    expect(imagePart).toBeDefined()
    expect(imagePart && 'alt' in imagePart ? imagePart.alt : undefined).toBeTruthy()
  })

  it('sets failed state + error and adds no media part', () => {
    const m = makeMessage()
    applyMediaJobUpdateToMessage(m, {
      job_id: 'job-1',
      message_id: 305,
      type: 'video',
      state: 'failed',
      error: 'Your video took too long.',
    })

    expect(m.mediaJob?.state).toBe('failed')
    expect(m.mediaJob?.error).toBe('Your video took too long.')
    expect(m.parts.some((p) => p.type === 'video')).toBe(false)
  })
})
