import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import GettingStartedChecklist from '@/components/chat/GettingStartedChecklist.vue'

const seen = ref<string[]>([])
const markSeen = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useTour', () => ({
  useTour: () => ({ seen, ensureLoaded: vi.fn().mockResolvedValue(undefined), markSeen }),
}))

const auth = { isAdmin: false }
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
const config = { setup: { chatReady: false as boolean | null } }
vi.mock('@/stores/config', () => ({ useConfigStore: () => config }))

const listFiles = vi.hoisted(() => vi.fn())
vi.mock('@/services/filesService', () => ({ listFiles }))
const agentsList = vi.hoisted(() => vi.fn())
vi.mock('@/services/api/agentsApi', () => ({ agentsApi: { list: agentsList } }))
vi.mock('@/apps/catalog', () => ({ availableApps: vi.fn().mockResolvedValue([]) }))
const loadConnectedAppIds = vi.hoisted(() => vi.fn())
vi.mock('@/apps/status', () => ({ loadConnectedAppIds }))

async function mountChecklist() {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }],
  })
  const wrapper = mount(GettingStartedChecklist, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

const item = (wrapper: Awaited<ReturnType<typeof mountChecklist>>, id: string) =>
  wrapper.find(`[data-testid="item-getting-started-${id}"]`)

describe('GettingStartedChecklist', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seen.value = []
    auth.isAdmin = false
    config.setup.chatReady = true
    listFiles.mockResolvedValue({ pagination: { total: 2 } })
    agentsList.mockResolvedValue([])
    loadConnectedAppIds.mockResolvedValue(new Set())
  })

  it('ticks each step from real data and says where the result is found', async () => {
    const wrapper = await mountChecklist()

    expect(item(wrapper, 'file').attributes('data-done')).toBe('true')
    expect(item(wrapper, 'assistant').attributes('data-done')).toBe('false')
    expect(item(wrapper, 'assistant').attributes('href')).toBe('/ai/assistants')
    expect(item(wrapper, 'file').text()).toContain('Library › Files')
    expect(item(wrapper, 'provider').exists()).toBe(false)
    expect(wrapper.get('[data-testid="text-getting-started-progress"]').text()).toContain('1 of 3')
  })

  it('asks an admin to set up an AI provider first', async () => {
    auth.isAdmin = true
    config.setup.chatReady = false
    const wrapper = await mountChecklist()

    expect(item(wrapper, 'provider').attributes('href')).toBe('/admin/setup')
    expect(item(wrapper, 'provider').attributes('data-done')).toBe('false')
  })

  it('leaves out a step whose feature cannot be checked', async () => {
    agentsList.mockRejectedValue(new Error('404'))
    const wrapper = await mountChecklist()

    expect(item(wrapper, 'assistant').exists()).toBe(false)
    expect(item(wrapper, 'file').exists()).toBe(true)
  })

  it('stays hidden once dismissed or finished', async () => {
    const wrapper = await mountChecklist()
    await wrapper.get('[data-testid="btn-getting-started-dismiss"]').trigger('click')
    expect(markSeen).toHaveBeenCalledWith('checklist.dismissed')

    seen.value = ['checklist.dismissed']
    expect((await mountChecklist()).find('[data-testid="section-getting-started"]').exists()).toBe(
      false
    )

    seen.value = []
    agentsList.mockResolvedValue([{ id: 1 }])
    loadConnectedAppIds.mockResolvedValue(new Set(['telegram']))
    expect((await mountChecklist()).find('[data-testid="section-getting-started"]').exists()).toBe(
      false
    )
  })
})
