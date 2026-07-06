import { describe, it, expect, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { applyMediaJobUpdateToMessage } from '@/utils/messageMapper'
import type { Message, TaskPlanState } from '@/stores/history'

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

function makeTaskPlan(overrides: Partial<TaskPlanState> = {}): TaskPlanState {
  return {
    active: false,
    replyNode: 'n3',
    cards: [
      {
        nodeId: 'n1',
        capability: 'image_generation',
        kind: 'image',
        state: 'running',
        jobId: 'job-1',
      },
      { nodeId: 'n2', capability: 'chat', kind: 'text', state: 'done', text: 'Miau.' },
    ],
    ...overrides,
  }
}

describe('applyMediaJobUpdateToMessage', () => {
  beforeEach(() => {
    // normalizeMediaUrl (task-card patch path) reads the config store.
    setActivePinia(createPinia())
  })

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

  // Multitask node jobs (#1239): the task card is the surface — the update
  // patches the card and must NOT set the single-task banner or append a
  // duplicate bubble-level media part next to the card.
  describe('multitask node job (node_id set)', () => {
    it('resolves the matching task card to done with the file url', () => {
      const m = makeMessage({ mediaJob: undefined, taskPlan: makeTaskPlan() })
      applyMediaJobUpdateToMessage(m, {
        job_id: 'job-1',
        message_id: 305,
        node_id: 'n1',
        type: 'image',
        state: 'done',
        file: { url: '/api/v1/files/uploads/cat.png', type: 'image' },
      })

      const card = m.taskPlan?.cards.find((c) => c.nodeId === 'n1')
      expect(card?.state).toBe('done')
      expect(card?.url).toContain('cat.png')
      expect(m.mediaJob).toBeUndefined()
      expect(m.parts.some((p) => p.type === 'image')).toBe(false)
    })

    it('marks the card failed with the error text', () => {
      const m = makeMessage({ mediaJob: undefined, taskPlan: makeTaskPlan() })
      applyMediaJobUpdateToMessage(m, {
        job_id: 'job-1',
        message_id: 305,
        node_id: 'n1',
        type: 'image',
        state: 'failed',
        error: 'Image generation failed.',
      })

      const card = m.taskPlan?.cards.find((c) => c.nodeId === 'n1')
      expect(card?.state).toBe('failed')
      expect(card?.error).toBe('Image generation failed.')
      expect(m.mediaJob).toBeUndefined()
    })

    it('never resurrects a user-cancelled card', () => {
      const plan = makeTaskPlan()
      plan.cards[0].state = 'cancelled'
      const m = makeMessage({ mediaJob: undefined, taskPlan: plan })
      applyMediaJobUpdateToMessage(m, {
        job_id: 'job-1',
        message_id: 305,
        node_id: 'n1',
        type: 'image',
        state: 'failed',
        error: 'aborted',
      })

      expect(m.taskPlan?.cards[0].state).toBe('cancelled')
    })

    it('falls back to the single-task path when no card matches the node id', () => {
      const m = makeMessage({ taskPlan: makeTaskPlan() })
      applyMediaJobUpdateToMessage(m, {
        job_id: 'job-9',
        message_id: 305,
        node_id: 'n9',
        type: 'video',
        state: 'done',
        file: { url: '/api/v1/files/uploads/x.mp4', type: 'video' },
      })

      expect(m.mediaJob?.state).toBe('done')
      expect(m.parts.some((p) => p.type === 'video')).toBe(true)
    })
  })
})
