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

const httpClient = vi.hoisted(() => vi.fn())
vi.mock('@/services/api/httpClient', () => ({ httpClient }))

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
    httpClient.mockResolvedValue({
      success: true,
      items: { file: true, assistant: false, app: false },
    })
  })

  it('asks once and ticks each step from that answer', async () => {
    const wrapper = await mountChecklist()

    expect(httpClient).toHaveBeenCalledTimes(1)
    expect(httpClient.mock.calls[0][0]).toBe('/api/v1/getting-started')
    expect(item(wrapper, 'file').attributes('data-done')).toBe('true')
    expect(item(wrapper, 'assistant').attributes('data-done')).toBe('false')
    expect(item(wrapper, 'assistant').attributes('href')).toBe('/ai/assistants')
    expect(item(wrapper, 'file').text()).toContain('Library › Files')
    expect(item(wrapper, 'provider').exists()).toBe(false)
    expect(wrapper.get('[data-testid="text-getting-started-progress"]').text()).toContain('1 of 3')
  })

  it('shows the provider step only when the answer includes it', async () => {
    httpClient.mockResolvedValue({
      success: true,
      items: { file: true, assistant: false, app: false, provider: false },
    })
    const wrapper = await mountChecklist()

    expect(item(wrapper, 'provider').attributes('href')).toBe('/admin/setup')
    expect(item(wrapper, 'provider').attributes('data-done')).toBe('false')
  })

  it('leaves out a step the answer omits', async () => {
    httpClient.mockResolvedValue({
      success: true,
      items: { file: true, app: false },
    })
    const wrapper = await mountChecklist()

    expect(item(wrapper, 'assistant').exists()).toBe(false)
    expect(item(wrapper, 'file').exists()).toBe(true)
  })

  it('stays hidden when the request fails, once dismissed, or when every step is done', async () => {
    httpClient.mockRejectedValue(new Error('down'))
    expect((await mountChecklist()).find('[data-testid="section-getting-started"]').exists()).toBe(
      false
    )

    httpClient.mockResolvedValue({
      success: true,
      items: { file: true, assistant: false, app: false },
    })
    const wrapper = await mountChecklist()
    await wrapper.get('[data-testid="btn-getting-started-dismiss"]').trigger('click')
    expect(markSeen).toHaveBeenCalledWith('checklist.dismissed')

    seen.value = ['checklist.dismissed']
    const callsBeforeDismissedMount = httpClient.mock.calls.length
    expect((await mountChecklist()).find('[data-testid="section-getting-started"]').exists()).toBe(
      false
    )
    expect(httpClient.mock.calls.length).toBe(callsBeforeDismissedMount)

    seen.value = []
    httpClient.mockResolvedValue({
      success: true,
      items: { file: true, assistant: true, app: true },
    })
    expect((await mountChecklist()).find('[data-testid="section-getting-started"]').exists()).toBe(
      false
    )
  })
})
