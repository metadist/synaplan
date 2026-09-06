import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SavedTasksOverview from '@/components/config/SavedTasksOverview.vue'
import type { SavedTask } from '@/services/api/savedTasksApi'

const { mockList } = vi.hoisted(() => ({
  mockList: vi.fn(),
}))

vi.mock('@/services/api/savedTasksApi', () => ({
  savedTasksApi: { list: mockList },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useIamFeature', () => ({
  isIamSharingEnabled: () => false,
}))

vi.mock('@/services/api/iamApi', () => ({
  iamApi: { listSharedWithMe: vi.fn().mockResolvedValue([]) },
}))

const task: SavedTask = {
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
  instructionPreview: 'Summarize my inbox',
}

const mountPage = async () => {
  const wrapper = mount(SavedTasksOverview, {
    global: {
      stubs: {
        Icon: true,
        RouterLink: { template: '<a><slot /></a>', props: ['to'] },
        SavedTaskCard: { template: '<div data-testid="saved-task-card" />', props: ['task'] },
      },
    },
  })
  await flushPromises()
  return wrapper
}

describe('SavedTasksOverview', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows the empty state when nothing is saved', async () => {
    mockList.mockResolvedValue([])
    const wrapper = await mountPage()
    expect(wrapper.get('[data-testid="saved-tasks-empty"]').text()).toContain('Nothing scheduled')
  })

  it('lists each saved task', async () => {
    mockList.mockResolvedValue([task])
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="saved-tasks-empty"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-testid="saved-task-card"]')).toHaveLength(1)
  })

  it('hides Shared with me when sharing is off', async () => {
    mockList.mockResolvedValue([task])
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="btn-shared-with-me"]').exists()).toBe(false)
  })
})
