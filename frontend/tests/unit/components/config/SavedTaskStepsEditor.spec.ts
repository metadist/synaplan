import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SavedTaskStepsEditor from '@/components/config/workflows/SavedTaskStepsEditor.vue'
import type { SavedTask } from '@/services/api/savedTasksApi'

const { mockUpdate, mockListTools, mockConfirm } = vi.hoisted(() => ({
  mockUpdate: vi.fn(),
  mockListTools: vi.fn(),
  mockConfirm: vi.fn(),
}))

vi.mock('@/services/api/savedTasksApi', () => ({
  savedTasksApi: { update: mockUpdate },
}))

vi.mock('@/services/api/toolsApi', () => ({
  toolsApi: { list: mockListTools },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: (...args: unknown[]) => mockConfirm(...args) }),
}))

function task(): SavedTask {
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
    summary: { key: 'config.savedTasks.summary.simple', params: { when: 'manual' } },
    instructionPreview: null,
    waitingApprovalCount: 0,
  }
}

describe('SavedTaskStepsEditor', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockListTools.mockResolvedValue([])
    mockUpdate.mockResolvedValue(task())
    mockConfirm.mockResolvedValue(true)
  })

  it('adds a step and saves a linear graph', async () => {
    const wrapper = mount(SavedTaskStepsEditor, {
      props: { open: true, task: task() },
      attachTo: document.body,
    })
    await flushPromises()
    const root = document.body
    const add = root.querySelector('[data-testid="btn-add-step"]')
    expect(add).toBeTruthy()
    await (add as HTMLButtonElement).click()
    await flushPromises()
    expect(root.querySelector('[data-testid="step-row-0"]')?.textContent).toContain('Step 1')
    const save = root.querySelector('[data-testid="btn-save-steps"]') as HTMLButtonElement
    save.click()
    await flushPromises()
    expect(mockUpdate).toHaveBeenCalledWith(
      7,
      expect.objectContaining({
        graph: expect.objectContaining({
          version: 1,
          trigger: { type: 'manual' },
          nodes: [
            expect.objectContaining({
              id: 'step_1',
              capability: 'chat',
              depends_on: [],
            }),
          ],
        }),
      })
    )
    wrapper.unmount()
  })
})
