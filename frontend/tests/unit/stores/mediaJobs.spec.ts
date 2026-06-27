import { describe, it, expect, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useMediaJobsStore } from '@/stores/mediaJobs'
import { useHistoryStore } from '@/stores/history'
import { useNotification } from '@/composables/useNotification'
import type { Message } from '@/stores/history'

/**
 * Sprint C: the `mediaJobs` store applies realtime `media_job.update` pushes to
 * the loaded message (instant in-place resolve) and raises an actionable
 * completion toast. Subscription wiring (RealtimeClient) is thin and mirrors the
 * widget-operator helper; the testable contract is `applyUpdate`.
 */
function seedMessage(
  history: ReturnType<typeof useHistoryStore>,
  overrides: Partial<Message> = {}
): Message {
  const m: Message = {
    id: 'local-1',
    role: 'assistant',
    parts: [{ type: 'text', content: '' }],
    timestamp: new Date(),
    backendMessageId: 305,
    mediaJob: { jobId: 'job-1', type: 'video', state: 'running' },
    ...overrides,
  } as Message
  history.messages.push(m)
  return m
}

describe('mediaJobs store · applyUpdate', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useNotification().notifications.value.splice(0)
  })

  it('patches the loaded message to done, adds the media part, and toasts success with an action', () => {
    const history = useHistoryStore()
    const message = seedMessage(history)

    useMediaJobsStore().applyUpdate({
      job_id: 'job-1',
      message_id: 305,
      chat_id: 201,
      type: 'video',
      state: 'done',
      file: { url: '/api/v1/files/uploads/x.mp4', type: 'video' },
    })

    expect(message.mediaJob?.state).toBe('done')
    expect(message.parts.some((p) => p.type === 'video')).toBe(true)

    const notes = useNotification().notifications.value
    expect(notes).toHaveLength(1)
    expect(notes[0].type).toBe('success')
    expect(notes[0].action?.label).toBeTruthy()
  })

  it('toasts an error and patches state on failure', () => {
    const history = useHistoryStore()
    const message = seedMessage(history)

    useMediaJobsStore().applyUpdate({
      job_id: 'job-1',
      message_id: 305,
      type: 'video',
      state: 'failed',
      error: 'Your video took too long.',
    })

    expect(message.mediaJob?.state).toBe('failed')
    expect(useNotification().notifications.value[0].type).toBe('error')
  })

  it('does not throw when the message is not loaded, but still toasts the terminal result', () => {
    expect(() =>
      useMediaJobsStore().applyUpdate({
        job_id: 'job-9',
        message_id: 9999,
        chat_id: 7,
        type: 'image',
        state: 'done',
        file: { url: '/api/v1/files/uploads/y.png', type: 'image' },
      })
    ).not.toThrow()

    expect(useNotification().notifications.value).toHaveLength(1)
  })

  it('ignores a running update (only terminal states toast)', () => {
    const history = useHistoryStore()
    const message = seedMessage(history)

    useMediaJobsStore().applyUpdate({
      job_id: 'job-1',
      message_id: 305,
      type: 'video',
      state: 'running',
      percent: 42,
    })

    expect(message.mediaJob?.percent).toBe(42)
    expect(useNotification().notifications.value).toHaveLength(0)
  })
})
