import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useMediaJobsStore } from '@/stores/mediaJobs'
import { useHistoryStore } from '@/stores/history'
import { useNotification } from '@/composables/useNotification'
import type { Message } from '@/stores/history'
import { fetchActiveMediaJobs, cancelMediaJob } from '@/services/api/mediaJobApi'

vi.mock('@/services/api/mediaJobApi', () => ({
  fetchActiveMediaJobs: vi.fn(),
  cancelMediaJob: vi.fn(),
}))

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

  it('upserts a running job into the tray and removes it on terminal', () => {
    const store = useMediaJobsStore()

    store.applyUpdate({
      job_id: 'job-1',
      message_id: 305,
      chat_id: 7,
      type: 'video',
      state: 'running',
      percent: 10,
    })
    expect(store.activeCount).toBe(1)
    expect(store.activeJobs[0]).toMatchObject({ jobId: 'job-1', state: 'running', percent: 10 })

    store.applyUpdate({
      job_id: 'job-1',
      message_id: 305,
      type: 'video',
      state: 'done',
      file: { url: '/x.mp4' },
    })
    expect(store.activeCount).toBe(0)
  })
})

describe('mediaJobs store · tray data layer', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useNotification().notifications.value.splice(0)
    vi.mocked(fetchActiveMediaJobs).mockReset()
    vi.mocked(cancelMediaJob).mockReset()
  })

  it('loadActive() populates the tray from the API', async () => {
    vi.mocked(fetchActiveMediaJobs).mockResolvedValue([
      {
        job_id: 'a',
        status: 'running',
        state: 'running',
        type: 'video',
        elapsed_seconds: 5,
        finished: false,
        percent: 30,
        chat_id: 7,
        message_id: 305,
        prompt: 'a cat',
      },
    ])

    const store = useMediaJobsStore()
    await store.loadActive()

    expect(store.activeCount).toBe(1)
    expect(store.activeJobs[0]).toMatchObject({
      jobId: 'a',
      state: 'running',
      percent: 30,
      chatId: 7,
      prompt: 'a cat',
    })
  })

  it('cancel() calls the API and drops the job from the tray', async () => {
    vi.mocked(cancelMediaJob).mockResolvedValue({
      job_id: 'a',
      status: 'cancelled',
      state: 'cancelled',
      type: 'video',
      elapsed_seconds: 9,
      finished: true,
    })

    const store = useMediaJobsStore()
    store.applyUpdate({ job_id: 'a', message_id: 305, chat_id: 7, type: 'video', state: 'running' })
    expect(store.activeCount).toBe(1)

    await store.cancel('a')

    expect(vi.mocked(cancelMediaJob)).toHaveBeenCalledWith('a')
    expect(store.activeCount).toBe(0)
  })

  it('cancel() surfaces an error toast when the API fails', async () => {
    vi.mocked(cancelMediaJob).mockRejectedValue(new Error('boom'))

    const store = useMediaJobsStore()
    await store.cancel('a')

    expect(useNotification().notifications.value[0].type).toBe('error')
  })
})
