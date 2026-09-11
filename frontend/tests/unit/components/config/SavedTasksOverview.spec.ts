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
  waitingApprovalCount: 0,
}

const TabNavStub = {
  props: ['modelValue', 'tabs'],
  emits: ['update:modelValue'],
  template: `
    <nav data-testid="tab-nav">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        type="button"
        :data-testid="tab.testid"
        :data-badge="tab.badge"
        @click="$emit('update:modelValue', tab.id)"
      >{{ tab.label }}</button>
    </nav>`,
}

const UrlWatchPanelStub = {
  emits: ['unavailable', 'count'],
  template:
    '<div data-testid="url-watch-panel"><button type="button" data-testid="emit-unavailable" @click="$emit(\'unavailable\')" /><button type="button" data-testid="emit-count" @click="$emit(\'count\', 2)" /></div>',
}

const mountPage = async () => {
  const wrapper = mount(SavedTasksOverview, {
    global: {
      stubs: {
        Icon: true,
        RouterLink: { template: '<a><slot /></a>', props: ['to'] },
        TabNav: TabNavStub,
        SavedTaskCard: { template: '<div data-testid="saved-task-card" />', props: ['task'] },
        UrlWatchPanel: UrlWatchPanelStub,
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

  it('removes a card when the child emits deleted', async () => {
    mockList.mockResolvedValue([task])
    const wrapper = mount(SavedTasksOverview, {
      global: {
        stubs: {
          Icon: true,
          RouterLink: { template: '<a><slot /></a>', props: ['to'] },
          TabNav: TabNavStub,
          SavedTaskCard: {
            props: ['task'],
            template:
              '<button type="button" data-testid="emit-deleted" @click="$emit(\'deleted\', task.id)" />',
          },
          UrlWatchPanel: UrlWatchPanelStub,
        },
      },
    })
    await flushPromises()
    await wrapper.get('[data-testid="emit-deleted"]').trigger('click')
    expect(wrapper.find('[data-testid="emit-deleted"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="saved-tasks-empty"]').exists()).toBe(true)
  })

  it('starts on Saved tasks and switches to Watched pages', async () => {
    mockList.mockResolvedValue([task])
    const wrapper = await mountPage()
    const tasksPane = wrapper.get('[data-testid="saved-tasks-pane"]')
    const watchesPane = wrapper.get('[data-testid="watched-pages-pane"]')
    const hidden = (pane: typeof tasksPane) =>
      (pane.attributes('style') ?? '').includes('display: none')
    expect(hidden(tasksPane)).toBe(false)
    expect(hidden(watchesPane)).toBe(true)
    expect(wrapper.get('[data-testid="tab-saved-tasks"]').attributes('data-badge')).toBe('1')

    await wrapper.get('[data-testid="tab-watched-pages"]').trigger('click')
    expect(hidden(tasksPane)).toBe(true)
    expect(hidden(watchesPane)).toBe(false)

    await wrapper.get('[data-testid="emit-count"]').trigger('click')
    expect(wrapper.get('[data-testid="tab-watched-pages"]').attributes('data-badge')).toBe('2')
  })

  it('drops the Watched pages tab when the feature is off on this instance', async () => {
    mockList.mockResolvedValue([task])
    const wrapper = await mountPage()
    await wrapper.get('[data-testid="tab-watched-pages"]').trigger('click')
    await wrapper.get('[data-testid="emit-unavailable"]').trigger('click')
    expect(wrapper.find('[data-testid="tab-nav"]').exists()).toBe(false)
    expect(
      (wrapper.get('[data-testid="saved-tasks-pane"]').attributes('style') ?? '').includes(
        'display: none'
      )
    ).toBe(false)
  })
})
