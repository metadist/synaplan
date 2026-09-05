import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SavedTaskCard from '@/components/config/SavedTaskCard.vue'
import type { SavedTask, SavedTaskRun } from '@/services/api/savedTasksApi'

const { mockUpdate, mockRun, mockRuns, mockResume, mockPush } = vi.hoisted(() => ({
  mockUpdate: vi.fn(),
  mockRun: vi.fn(),
  mockRuns: vi.fn(),
  mockResume: vi.fn(),
  mockPush: vi.fn(),
}))

vi.mock('@/services/api/savedTasksApi', () => ({
  savedTasksApi: {
    update: mockUpdate,
    run: mockRun,
    runs: mockRuns,
    resume: mockResume,
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useIamFeature', () => ({
  isIamSharingEnabled: () => false,
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn().mockResolvedValue(false) }),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: mockPush }),
}))

function task(overrides: Partial<SavedTask> = {}): SavedTask {
  return {
    id: 7,
    promptId: 12,
    name: 'Meeting requests',
    enabled: true,
    triggerType: 'manual',
    triggerConfig: null,
    graph: null,
    allowUnattended: false,
    chatId: null,
    nextRunAt: null,
    lastRunAt: null,
    consecutiveFailures: 0,
    autoPaused: false,
    summary: {
      key: 'config.savedTasks.summary.simple',
      params: { when: 'manual' },
    },
    instructionPreview: 'Create a realistic picture of a cat, soft natural light',
    ...overrides,
  }
}

function run(overrides: Partial<SavedTaskRun> = {}): SavedTaskRun {
  return {
    id: 1,
    status: 'completed',
    trigger: 'manual',
    messageId: 99,
    planSnapshot: null,
    error: null,
    started: '2026-08-15T07:00:00+00:00',
    finished: '2026-08-15T07:00:12+00:00',
    created: 20260815070000,
    ...overrides,
  }
}

const mountCard = (value: SavedTask) =>
  mount(SavedTaskCard, {
    props: { task: value },
    global: {
      stubs: { Icon: true, ShareDialog: true },
    },
  })

describe('SavedTaskCard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUpdate.mockImplementation(async (_id: number, patch: Record<string, unknown>) =>
      task({ ...patch } as Partial<SavedTask>)
    )
    mockResume.mockResolvedValue(task({ enabled: true, autoPaused: false, consecutiveFailures: 0 }))
  })

  it('shows the off state when the task is disabled', () => {
    const wrapper = mountCard(task({ enabled: false }))
    expect(wrapper.get('[data-testid="saved-task-last-run"]').text()).toContain('Not running')
    expect(wrapper.get('[data-testid="saved-task-enabled"]').attributes('checked')).toBeUndefined()
  })

  it('shows never-run copy when the task has no history', () => {
    const wrapper = mountCard(task())
    expect(wrapper.get('[data-testid="saved-task-last-run"]').text()).toContain(
      'Saved — not run yet'
    )
  })

  it('shows the scheduled first-run line with a readable date', () => {
    const wrapper = mountCard(
      task({
        triggerType: 'schedule',
        nextRunAt: '2026-08-17T05:00:00+00:00',
        triggerConfig: { kind: 'weekly', at: '07:00', tz: 'Europe/Berlin', days: [1, 2, 3, 4, 5] },
      })
    )
    const text = wrapper.get('[data-testid="saved-task-last-run"]').text()
    expect(text).toContain('Scheduled')
    // Raw ISO strings are formatted for humans, never shown verbatim.
    expect(text).not.toContain('2026-08-17T05:00:00+00:00')
    expect(text).toContain('2026')
  })

  it('shows the start of the instruction so users know what runs', () => {
    const wrapper = mountCard(task())
    expect(wrapper.get('[data-testid="saved-task-preview"]').text()).toContain(
      'Create a realistic picture of a cat'
    )
  })

  it('hides the preview line when the instruction is unavailable', () => {
    const wrapper = mountCard(task({ instructionPreview: null }))
    expect(wrapper.find('[data-testid="saved-task-preview"]').exists()).toBe(false)
  })

  it('renders the summary in one language from part codes', () => {
    const wrapper = mountCard(
      task({
        summary: {
          key: 'config.savedTasks.summary.template',
          params: {
            when: 'daily',
            at: '07:00',
            tz: 'Europe/Berlin',
            reads: 'mailbox',
            saves: 'email',
          },
        },
      })
    )
    const text = wrapper.text()
    expect(text).toContain('Runs every day at 07:00 (Europe/Berlin)')
    expect(text).toContain('the connected mailbox')
    expect(text).toContain('an email to you')
  })

  it('falls back to safe wording for unknown summary codes', () => {
    const wrapper = mountCard(
      task({
        summary: {
          key: 'config.savedTasks.summary.simple',
          params: { when: 'some_future_code' },
        },
      })
    )
    expect(wrapper.text()).toContain('Runs when you start it.')
  })

  it('opens the task chat via Show results so users see the run output', async () => {
    const wrapper = mountCard(task({ chatId: 44 }))
    await wrapper.get('[data-testid="btn-show-results"]').trigger('click')
    expect(mockPush).toHaveBeenCalledWith({ path: '/', query: { chat: '44' } })
  })

  it('hides Show results until the task has a chat', () => {
    const wrapper = mountCard(task({ chatId: null }))
    expect(wrapper.find('[data-testid="btn-show-results"]').exists()).toBe(false)
  })

  it('runs immediately without asking for a message', async () => {
    let resolveRun: (value: { task: SavedTask; run: SavedTaskRun }) => void = () => undefined
    mockRun.mockReturnValue(
      new Promise((resolve) => {
        resolveRun = resolve
      })
    )
    const wrapper = mountCard(task())
    await wrapper.get('[data-testid="btn-run-now"]').trigger('click')
    await flushPromises()
    // One click, one run — the stored instruction is used, no dialog.
    expect(mockRun).toHaveBeenCalledWith(7)
    expect(wrapper.get('[data-testid="saved-task-last-run"]').text()).toContain('Running now')
    resolveRun({
      task: task({ lastRunAt: '2026-08-15T12:00:00+00:00', chatId: 44 }),
      run: run(),
    })
    await flushPromises()
    expect(mockPush).toHaveBeenCalledWith({ path: '/', query: { chat: '44' } })
  })

  it('shows the auto-pause notice and resume control', async () => {
    const wrapper = mountCard(task({ enabled: false, autoPaused: true, consecutiveFailures: 3 }))
    expect(wrapper.get('[data-testid="saved-task-auto-pause"]').text()).toContain(
      'Paused automatically'
    )
    await wrapper.get('[data-testid="saved-task-auto-pause"] button').trigger('click')
    expect(mockResume).toHaveBeenCalledWith(7)
  })

  it('lists failed runs with the plain-language reason', async () => {
    mockRuns.mockResolvedValue({
      runs: [
        run({ status: 'failed', error: 'Your usage limit was reached, so this run was skipped.' }),
      ],
      retention: 'Last 50 runs or 90 days, whichever keeps more history.',
    })
    const wrapper = mountCard(task({ lastRunAt: '2026-08-15T12:00:00+00:00' }))
    await wrapper.get('[data-testid="btn-view-runs"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="saved-task-runs"]').text()).toContain(
      'Your usage limit was reached, so this run was skipped.'
    )
  })

  it('localizes run status and trigger instead of raw enum codes', async () => {
    mockRuns.mockResolvedValue({
      runs: [run({ status: 'completed', trigger: 'schedule' })],
      retention: 'ignored backend prose',
    })
    const wrapper = mountCard(task({ lastRunAt: '2026-08-15T12:00:00+00:00' }))
    await wrapper.get('[data-testid="btn-view-runs"]').trigger('click')
    await flushPromises()
    const text = wrapper.get('[data-testid="saved-task-runs"]').text()
    expect(text).toContain('Completed')
    expect(text).toContain('scheduled run')
    // The English retention prose from the backend is never shown raw.
    expect(text).not.toContain('ignored backend prose')
    expect(text).toContain('Older runs are removed automatically.')
  })

  it('hides Share and run-as-copy when sharing is off', () => {
    const wrapper = mountCard(task())
    expect(wrapper.find('[data-testid="btn-share-saved-task"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-run-copy"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="btn-run-now"]').exists()).toBe(true)
  })
})
