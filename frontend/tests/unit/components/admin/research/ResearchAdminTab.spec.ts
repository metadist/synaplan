import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ResearchAdminTab from '@/components/admin/research/ResearchAdminTab.vue'
import { useNotification } from '@/composables/useNotification'
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

function accept<T extends ReturnType<typeof off>>(base: T, body: Record<string, unknown>): T {
  const next = structuredClone(base)
  const budgets = next.budgets as Record<string, Record<string, number>>
  if (body.budgets && typeof body.budgets === 'object') {
    for (const [depth, row] of Object.entries(
      body.budgets as Record<string, Record<string, number>>
    )) {
      budgets[depth] = { ...(budgets[depth] ?? {}), ...row }
    }
  }
  if (body.limits && typeof body.limits === 'object') {
    next.limits = { ...next.limits, ...(body.limits as T['limits']) }
  }
  return next
}

describe('ResearchAdminTab', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useNotification().notifications.value.splice(0)
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

    const toggle = wrapper.get('[data-testid="research-admin-enabled"]')
    expect(toggle.attributes('aria-checked')).toBe('false')
    await toggle.trigger('click')
    await flushPromises()

    expect(saveResearchAdmin).toHaveBeenCalledWith({ enabled: true })
    expect(wrapper.text()).toContain('People can no longer start research')
    expect(wrapper.get('[data-testid="research-admin-enabled"]').attributes('aria-checked')).toBe(
      'true'
    )
  })

  it('shows a translated refusal next to the switch only', async () => {
    saveResearchAdmin.mockRejectedValue(refusal(409, 'env_locked', 'enabled'))
    const wrapper = mount(ResearchAdminTab)
    await flushPromises()

    await wrapper.get('[data-testid="research-admin-enabled"]').trigger('click')
    await flushPromises()

    const status = wrapper.get('[data-testid="research-admin-status"]').text()
    expect(status).toContain('This switch is set by the server')
    expect(status).not.toContain('English only')
    expect(wrapper.find('[data-testid="research-admin-save-error"]').exists()).toBe(false)
    expect(useNotification().notifications.value).toHaveLength(0)
  })

  it('keeps the save row in place, discards a typed limit, and stores it on blur', async () => {
    let state = off()
    saveResearchAdmin.mockImplementation(async (body: Record<string, unknown>) => {
      state = accept(state, body)
      return state
    })
    const wrapper = mount(ResearchAdminTab)
    await flushPromises()

    expect(wrapper.find('[data-testid="section-unsaved-bar"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="research-admin-save"]').exists()).toBe(false)
    const input = wrapper.get('[data-testid="research-admin-standard-costCap"]')
    ;(input.element as HTMLInputElement).value = '2.5'
    await input.trigger('input')

    expect(saveResearchAdmin).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="section-unsaved-bar"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="research-admin-save-status"]').text()).toContain(
      'Leave the field'
    )
    expect(wrapper.get('[data-testid="research-admin-tab"]').classes()).not.toContain('pb-40')

    await input.trigger('change')
    await flushPromises()

    const body = saveResearchAdmin.mock.calls.at(-1)?.[0] as {
      budgets: { standard: { costCap: number } }
    }
    expect(body.budgets.standard.costCap).toBe(2.5)
    expect(wrapper.find('[data-testid="section-unsaved-bar"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="research-admin-save-status"]').text()).toBe('Saved')

    const runs = wrapper.get('[data-testid="research-admin-maxActiveRuns"]')
    await runs.setValue('8')
    await flushPromises()
    const limits = saveResearchAdmin.mock.calls.at(-1)?.[0] as {
      limits: { maxActiveRuns: number }
    }
    expect(limits.limits.maxActiveRuns).toBe(8)
    expect(wrapper.find('[data-testid="section-unsaved-bar"]').exists()).toBe(false)
  })

  it('refuses an out-of-range value without calling the server', async () => {
    const wrapper = mount(ResearchAdminTab)
    await flushPromises()

    await wrapper.get('[data-testid="research-admin-standard-searches"]').setValue('500')
    await flushPromises()

    expect(saveResearchAdmin).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="research-admin-save-error"]').text()).toContain(
      'Standard · Searches is outside its allowed range'
    )
    expect(useNotification().notifications.value.at(-1)).toMatchObject({
      type: 'error',
      message: 'Standard · Searches is outside its allowed range. Nothing was saved.',
    })
  })

  it('sends a model only when it changed', async () => {
    const view = {
      ...off(),
      budgets: {
        ...off().budgets,
        auto: { searches: 10, sources: 15, minutes: 6, costCap: 1 },
      },
    }
    fetchResearchAdmin.mockResolvedValue(view)
    let state = view
    saveResearchAdmin.mockImplementation(async (body: Record<string, unknown>) => {
      state = accept(state, body)
      return state
    })
    const wrapper = mount(ResearchAdminTab)
    await flushPromises()

    expect(wrapper.find('[data-testid="section-unsaved-bar"]').exists()).toBe(false)
    await wrapper.get('[data-testid="research-admin-quick-costCap"]').setValue('0.2')
    await flushPromises()

    const body = saveResearchAdmin.mock.calls[0]?.[0] as {
      budgets: Record<string, unknown>
    }
    expect(body).not.toHaveProperty('readingModelId')
    expect(body).not.toHaveProperty('checkingModelId')
    expect(body.budgets).not.toHaveProperty('auto')
    expect(wrapper.find('[data-testid="section-unsaved-bar"]').exists()).toBe(false)
    expect(useNotification().notifications.value.at(-1)).toMatchObject({
      type: 'success',
      message: 'Changes saved successfully',
    })
  })

  it('does not start a sample until the saved cost is accepted', async () => {
    const view = { ...off(), enabled: true }
    fetchResearchAdmin.mockResolvedValue(view)
    let state = view
    saveResearchAdmin.mockImplementation(async (body: Record<string, unknown>) => {
      state = accept(state, body)
      return state
    })
    const wrapper = mount(ResearchAdminTab)
    await flushPromises()

    const button = wrapper.get('[data-testid="research-admin-sample"]')
    expect(wrapper.text()).toContain('I accept a cost of up to $0.10.')
    expect(button.attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="research-admin-sample-confirm"]').setValue(true)
    expect(button.attributes('disabled')).toBeUndefined()

    const cap = wrapper.get('[data-testid="research-admin-quick-costCap"]')
    ;(cap.element as HTMLInputElement).value = '3'
    await cap.trigger('input')
    expect(wrapper.find('[data-testid="research-admin-sample-dirty"]').exists()).toBe(true)
    expect(button.attributes('disabled')).toBeDefined()
    expect(startResearchSample).not.toHaveBeenCalled()

    await cap.trigger('change')
    await flushPromises()
    expect(wrapper.find('[data-testid="research-admin-sample-dirty"]').exists()).toBe(false)
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
