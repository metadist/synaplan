import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ResearchAutoSwitch from '@/components/admin/research/ResearchAutoSwitch.vue'
import { useResearchStore } from '@/stores/research'
import { ApiError } from '@/services/api/httpClient'

const saveResearchAdmin = vi.fn()

vi.mock('@/services/api/researchAdminApi', () => ({
  saveResearchAdmin: (...args: unknown[]) => saveResearchAdmin(...args),
}))

const view = (autoEnabled: boolean) => ({ enabled: true, autoEnabled, webSearchAvailable: true })

const mountSwitch = (props: Partial<InstanceType<typeof ResearchAutoSwitch>['$props']> = {}) =>
  mount(ResearchAutoSwitch, {
    props: { researchOn: true, autoEnabled: true, webSearchAvailable: true, ...props },
  })

describe('ResearchAutoSwitch', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    saveResearchAdmin.mockReset()
  })

  it('turns automatic research off, says what that means, and drops the cached capabilities', async () => {
    saveResearchAdmin.mockResolvedValue(view(false))
    const store = useResearchStore()
    const forget = vi.spyOn(store, 'forgetCapabilities')
    const wrapper = mountSwitch()

    expect(wrapper.find('[data-testid="research-admin-auto-consequence"]').exists()).toBe(false)
    await wrapper.get('[data-testid="research-admin-auto-turn-off"]').trigger('click')
    await flushPromises()

    expect(saveResearchAdmin).toHaveBeenCalledWith({ autoEnabled: false })
    expect(forget).toHaveBeenCalledOnce()
    expect(wrapper.emitted('saved')?.[0]).toEqual([view(false)])

    await wrapper.setProps({ autoEnabled: false })
    expect(wrapper.get('[data-testid="research-admin-auto-consequence"]').text()).toContain(
      'Off: the chat answers every question directly'
    )
    expect(wrapper.get('[data-testid="research-admin-auto-turn-on"]').text()).toBe('Turn on')
  })

  it('is disabled with one sentence while research is off', () => {
    const wrapper = mountSwitch({ researchOn: false })

    expect(wrapper.get('[data-testid="research-admin-auto-needs-research"]').text()).toBe(
      'Turn on Research first.'
    )
    expect(
      wrapper.get('[data-testid="research-admin-auto-turn-off"]').attributes('disabled')
    ).toBeDefined()
    expect(wrapper.find('[data-testid="research-admin-auto-consequence"]').exists()).toBe(false)
  })

  it('says that only folders are read when web search is off', () => {
    const wrapper = mountSwitch({ webSearchAvailable: false })

    expect(wrapper.get('[data-testid="research-admin-auto-no-web"]').text()).toContain(
      'Web search is off'
    )
  })

  it('shows the refusal and keeps the switch when the server says no', async () => {
    saveResearchAdmin.mockRejectedValue(
      new ApiError(409, 'refused', undefined, {
        error: { code: 'research_off', field: 'autoEnabled', message: 'English only' },
      })
    )
    const wrapper = mountSwitch({ autoEnabled: false })

    await wrapper.get('[data-testid="research-admin-auto-turn-on"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toBe('Turn on Research first.')
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('names a plain failure without pretending anything changed', async () => {
    saveResearchAdmin.mockRejectedValue(new Error('offline'))
    const wrapper = mountSwitch()

    await wrapper.get('[data-testid="research-admin-auto-turn-off"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Automatic research stays as it was')
  })
})
