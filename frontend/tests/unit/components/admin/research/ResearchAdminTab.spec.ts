import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ResearchAdminTab from '@/components/admin/research/ResearchAdminTab.vue'
import { ApiError } from '@/services/api/httpClient'

const fetchResearchAdmin = vi.fn()
const saveResearchAdmin = vi.fn()
const startResearchSample = vi.fn()

vi.mock('@/services/api/researchAdminApi', () => ({
  fetchResearchAdmin: (...args: unknown[]) => fetchResearchAdmin(...args),
  saveResearchAdmin: (...args: unknown[]) => saveResearchAdmin(...args),
  startResearchSample: (...args: unknown[]) => startResearchSample(...args),
}))

const off = () => ({
  enabled: false,
  envLocked: false,
  autoEnabled: true,
  webSearchAvailable: true,
  readingModelId: null,
  checkingModelId: null,
  models: [{ id: 3, name: 'Atlas', available: true, reason: null }],
  limits: { maxActiveRuns: 5, maxActiveThorough: 1 },
  budgets: {
    quick: { searches: 2, sources: 4, minutes: 1, costCap: 0.1 },
    standard: { searches: 10, sources: 15, minutes: 6, costCap: 1 },
    thorough: { searches: 30, sources: 40, minutes: 20, costCap: 5 },
  },
  sample: null as null | { id: string; status: 'running' | 'done'; sentence: string | null },
})

const refusal = (status: number, code: string, field: string | null = null) =>
  new ApiError(status, 'refused', undefined, { error: { code, field, message: 'English only' } })

describe('ResearchAdminTab', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    fetchResearchAdmin.mockReset()
    saveResearchAdmin.mockReset()
    startResearchSample.mockReset()
    fetchResearchAdmin.mockResolvedValue(off())
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('says research is off and turns it on', async () => {
    saveResearchAdmin.mockResolvedValue({ ...off(), enabled: true })
    const wrapper = mount(ResearchAdminTab)
    await flushPromises()

    expect(wrapper.get('[data-testid="research-admin-status"]').text()).toContain('Research is off')
    expect(
      wrapper.get('[data-testid="research-admin-sample"]').attributes('disabled')
    ).toBeDefined()

    await wrapper.get('[data-testid="research-admin-turn-on"]').trigger('click')
    await flushPromises()

    expect(saveResearchAdmin).toHaveBeenCalledWith({ enabled: true })
    expect(wrapper.text()).toContain('People can no longer start research')
    expect(wrapper.find('[data-testid="research-admin-turn-off"]').exists()).toBe(true)
  })

  it('shows a translated refusal next to the switch only', async () => {
    saveResearchAdmin.mockRejectedValue(refusal(409, 'env_locked', 'enabled'))
    const wrapper = mount(ResearchAdminTab)
    await flushPromises()

    await wrapper.get('[data-testid="research-admin-turn-on"]').trigger('click')
    await flushPromises()

    const status = wrapper.get('[data-testid="research-admin-status"]').text()
    expect(status).toContain('This switch is set by the server')
    expect(status).not.toContain('English only')
    expect(
      wrapper.get('[data-testid="research-admin-save"]').element.parentElement?.textContent
    ).not.toContain('This switch is set by the server')
  })

  it('refuses an out-of-range value without calling the server', async () => {
    const wrapper = mount(ResearchAdminTab)
    await flushPromises()

    await wrapper.get('[data-testid="research-admin-standard-searches"]').setValue('500')
    await wrapper.get('[data-testid="research-admin-save"]').trigger('click')
    await flushPromises()

    expect(saveResearchAdmin).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Standard · Searches is outside its allowed range')
  })

  it('sends a model only when it changed', async () => {
    saveResearchAdmin.mockImplementation(async (body: Record<string, unknown>) => ({
      ...off(),
      budgets: body.budgets,
    }))
    const wrapper = mount(ResearchAdminTab)
    await flushPromises()

    expect(wrapper.get('[data-testid="research-admin-save"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="research-admin-quick-costCap"]').setValue('0.2')
    await wrapper.get('[data-testid="research-admin-save"]').trigger('click')
    await flushPromises()

    const body = saveResearchAdmin.mock.calls[0]?.[0] as Record<string, unknown>
    expect(body).not.toHaveProperty('readingModelId')
    expect(body).not.toHaveProperty('checkingModelId')
    expect(wrapper.text()).toContain('Saved')
  })

  it('does not start a sample until the saved cost is accepted', async () => {
    fetchResearchAdmin.mockResolvedValue({ ...off(), enabled: true })
    const wrapper = mount(ResearchAdminTab)
    await flushPromises()

    const button = wrapper.get('[data-testid="research-admin-sample"]')
    expect(wrapper.text()).toContain('I accept a cost of up to $0.10.')
    expect(button.attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="research-admin-sample-confirm"]').setValue(true)
    expect(button.attributes('disabled')).toBeUndefined()

    await wrapper.get('[data-testid="research-admin-quick-costCap"]').setValue('3')
    expect(wrapper.find('[data-testid="research-admin-sample-dirty"]').exists()).toBe(true)
    expect(button.attributes('disabled')).toBeDefined()
    expect(startResearchSample).not.toHaveBeenCalled()
  })

  it('follows a running sample until it is done', async () => {
    vi.useFakeTimers()
    fetchResearchAdmin.mockResolvedValueOnce({
      ...off(),
      enabled: true,
      sample: { id: 'r1', status: 'running', sentence: null },
    })
    fetchResearchAdmin.mockResolvedValue({
      ...off(),
      enabled: true,
      sample: { id: 'r1', status: 'done', sentence: 'Done: 4 statements, 4 backed by a source.' },
    })
    const wrapper = mount(ResearchAdminTab)
    await flushPromises()

    expect(wrapper.find('[data-testid="research-admin-sample-running"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="research-admin-sample"]').exists()).toBe(false)

    await vi.advanceTimersByTimeAsync(5000)
    await flushPromises()

    expect(wrapper.get('[data-testid="research-admin-sample-result"]').text()).toContain(
      'Done: 4 statements'
    )
    wrapper.unmount()
  })
})
