import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import TaskPromptsConfiguration from '@/components/config/TaskPromptsConfiguration.vue'

const getPrompts = vi.hoisted(() => vi.fn())
vi.mock('@/services/api/promptsApi', () => ({
  promptsApi: {
    getPrompts,
    getAvailableFiles: vi.fn().mockResolvedValue([]),
    getPromptFiles: vi.fn().mockResolvedValue([]),
  },
}))
vi.mock('@/services/api/configApi', () => ({
  configApi: { getModels: vi.fn().mockResolvedValue({ success: true, models: {} }) },
}))
vi.mock('@/services/api/savedTasksApi', () => ({
  savedTasksApi: { list: vi.fn().mockResolvedValue([]) },
}))
vi.mock('@/services/api/iamApi', () => ({
  iamApi: { listSharedWithMe: vi.fn().mockResolvedValue([]) },
}))
vi.mock('@/composables/useSavedTasksFeature', () => ({ isSavedTasksEnabled: () => false }))
vi.mock('@/composables/useIamFeature', () => ({ isIamSharingEnabled: () => false }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ isAdmin: false, user: { id: 1 } }),
}))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))
vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: vi.fn(), prompt: vi.fn() }),
}))
vi.mock('@/composables/useUnsavedChanges', () => ({
  useUnsavedChanges: () => ({
    hasUnsavedChanges: ref(false),
    saveChanges: vi.fn(),
    discardChanges: vi.fn(),
    setupNavigationGuard: () => () => {},
  }),
}))

function deferred<T>() {
  let resolve: (value: T) => void = () => {}
  let reject: (reason?: unknown) => void = () => {}
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

const stubs = {
  Icon: true,
  PageHeader: { template: '<div><slot name="actions" /><slot /></div>' },
  ModelSelectDropdown: true,
  UnsavedChangesBar: true,
  SavedTaskCard: true,
  ShareDialog: true,
  SharedResourceBanner: true,
}

describe('TaskPromptsConfiguration', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows a loading state instead of an empty search while topics are in flight', async () => {
    const pending = deferred<unknown[]>()
    getPrompts.mockReturnValue(pending.promise)
    const wrapper = mount(TaskPromptsConfiguration, { global: { stubs } })
    await flushPromises()

    expect(wrapper.find('[data-testid="task-prompts-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="text-no-prompts-match"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="stat-total"]').text()).not.toContain('0')

    pending.resolve([])
    await flushPromises()

    expect(wrapper.find('[data-testid="task-prompts-loading"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="text-no-prompts"]').text()).toContain('No topics yet.')
    expect(wrapper.find('[data-testid="btn-no-prompts-create"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="text-no-prompts-match"]').exists()).toBe(false)
  })

  it('says nothing matches only when a search or filter is active', async () => {
    getPrompts.mockResolvedValue([
      {
        id: 1,
        topic: 'general',
        name: 'General',
        prompt: 'Hello',
        language: 'en',
        metadata: {},
      },
    ])
    const wrapper = mount(TaskPromptsConfiguration, { global: { stubs } })
    await flushPromises()

    await wrapper.get('[data-testid="input-prompt-search"]').setValue('zzzz-not-a-topic')

    expect(wrapper.get('[data-testid="text-no-prompts-match"]').text()).toContain(
      'No topics match your search.'
    )
    expect(wrapper.find('[data-testid="text-no-prompts"]').exists()).toBe(false)
  })

  it('shows the error state when the topic list fails', async () => {
    getPrompts.mockRejectedValue(new Error('HTTP 500'))
    const wrapper = mount(TaskPromptsConfiguration, { global: { stubs } })
    await flushPromises()

    expect(wrapper.get('[data-testid="task-prompts-load-error"]').text()).toContain(
      'Topics could not be loaded.'
    )
    expect(wrapper.find('[data-testid="text-no-prompts"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="text-no-prompts-match"]').exists()).toBe(false)
  })
})
