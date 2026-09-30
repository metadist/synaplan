import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SmartSearchModelsCard from '@/components/admin/search/SmartSearchModelsCard.vue'
import { ApiError } from '@/services/api/httpClient'
import type { AdminSearchConfig } from '@/services/api/adminSearchApi'

const getAdminSearchConfig = vi.fn()
const putAdminSearchConfig = vi.fn()
const confirm = vi.fn()
const push = vi.fn()
const error = vi.fn()
const info = vi.fn()
const success = vi.fn()

vi.mock('@/services/api/adminSearchApi', () => ({
  getAdminSearchConfig: (...args: unknown[]) => getAdminSearchConfig(...args),
  putAdminSearchConfig: (...args: unknown[]) => putAdminSearchConfig(...args),
}))
vi.mock('@/composables/useDialog', () => ({ useDialog: () => ({ confirm }) }))
vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ push, error, info, success }),
}))

const option = (id: number, name: string, available = true) => ({
  id,
  name,
  service: 'Test',
  available,
  reason: available ? null : ('provider_unavailable' as const),
})

const config = (overrides: Partial<AdminSearchConfig> = {}): AdminSearchConfig => ({
  success: true,
  ai: {
    selectedModelId: null,
    inheritedModelId: 1,
    inheritedModelName: 'Tools (Test)',
    effectiveModelId: 1,
    effectiveModelName: 'Tools (Test)',
    options: [option(1, 'Tools'), option(2, 'Fast'), option(3, 'Keyless', false)],
  },
  embed: {
    selectedModelId: null,
    inheritedModelId: 10,
    inheritedModelName: 'Docs embed (Test)',
    effectiveModelId: 10,
    effectiveModelName: 'Docs embed (Test)',
    options: [option(10, 'Docs embed'), option(11, 'Other embed')],
  },
  aiEnabled: true,
  aiAvailable: true,
  index: { rows: 40, embeddedRows: 40, semanticAvailable: true },
  activeRun: null,
  otherRunActive: false,
  latestRun: null,
  ...overrides,
})

const mountCard = async () => {
  const wrapper = mount(SmartSearchModelsCard, {
    global: { stubs: { RouterLink: { template: '<a><slot /></a>', props: ['to'] } } },
  })
  await flushPromises()
  return wrapper
}

const choose = async (
  wrapper: Awaited<ReturnType<typeof mountCard>>,
  id: string,
  value: string
) => {
  const select = wrapper.get(`[data-testid="select-${id}"]`)
  ;(select.element as HTMLSelectElement).value = value
  await select.trigger('change')
  await flushPromises()
}

describe('SmartSearchModelsCard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getAdminSearchConfig.mockResolvedValue(config())
  })

  it('names the inherited models and disables a model without a key, with the reason', async () => {
    const wrapper = await mountCard()
    const ai = wrapper.get('[data-testid="select-smart-search-ai-model"]')
    expect(ai.text()).toContain('Same as the tools model: Tools (Test)')
    const keyless = ai.findAll('option').find((o) => o.text().startsWith('Keyless'))
    expect(keyless?.attributes('disabled')).toBeDefined()
    expect(keyless?.text()).toContain('no API key for Test')
    expect(wrapper.get('[data-testid="smart-search-index-status"]').text()).toContain(
      '40 of 40 index entries can be found by meaning.'
    )
  })

  it('names an inherited model that is not offered as an option', async () => {
    getAdminSearchConfig.mockResolvedValue(
      config({
        embed: {
          ...config().embed,
          inheritedModelId: -2,
          inheritedModelName: 'stub (test)',
          effectiveModelId: -2,
          effectiveModelName: 'stub (test)',
        },
      })
    )
    const wrapper = await mountCard()
    expect(wrapper.get('[data-testid="select-smart-search-embed-model"]').text()).toContain(
      'stub (test)'
    )
  })

  it('switches the AI model at once and offers Undo back to inherit', async () => {
    const changed = config({
      ai: {
        ...config().ai,
        selectedModelId: 2,
        effectiveModelId: 2,
        effectiveModelName: 'Fast (Test)',
      },
    })
    putAdminSearchConfig.mockResolvedValue({
      success: true,
      previousModelId: null,
      runId: null,
      config: changed,
    })
    const wrapper = await mountCard()

    await choose(wrapper, 'smart-search-ai-model', '2')

    expect(putAdminSearchConfig).toHaveBeenCalledWith('ai', 2)
    expect(confirm).not.toHaveBeenCalled()
    const toast = push.mock.calls[0]![0]
    expect(toast.message).toBe('Search questions now use Fast (Test).')

    putAdminSearchConfig.mockResolvedValue({
      success: true,
      previousModelId: 2,
      runId: null,
      config: config(),
    })
    toast.action.onClick()
    await flushPromises()
    expect(putAdminSearchConfig).toHaveBeenLastCalledWith('ai', null)
    expect(success).toHaveBeenCalledWith('Search questions use Tools (Test) again.')
  })

  it('asks before an embedding change and keeps the model when cancelled', async () => {
    confirm.mockResolvedValue(false)
    const wrapper = await mountCard()

    await choose(wrapper, 'smart-search-embed-model', '11')

    expect(confirm.mock.calls[0]![0].message).toContain(
      're-reads 40 entries with Other embed (Test)'
    )
    expect(putAdminSearchConfig).not.toHaveBeenCalled()
    const select = wrapper.get('[data-testid="select-smart-search-embed-model"]')
    expect((select.element as HTMLSelectElement).value).toBe('')
  })

  it('shows progress while the index is re-read and a terminal sentence afterwards', async () => {
    vi.useFakeTimers()
    const running = {
      id: 5,
      status: 'running' as const,
      fromModelId: 10,
      toModelId: 11,
      rowsTotal: 40,
      rowsProcessed: 10,
      rowsFailed: 0,
      finishedAt: null,
    }
    getAdminSearchConfig.mockResolvedValueOnce(config({ activeRun: running }))
    getAdminSearchConfig.mockResolvedValueOnce(
      config({
        latestRun: {
          ...running,
          status: 'failed',
          rowsProcessed: 0,
          rowsFailed: 40,
          finishedAt: 1,
        },
      })
    )
    const wrapper = await mountCard()

    expect(wrapper.get('[data-testid="text-smart-search-run"]').text()).toContain('10 of 40')
    expect(
      wrapper.get('[data-testid="select-smart-search-embed-model"]').attributes('disabled')
    ).toBeDefined()

    await vi.advanceTimersByTimeAsync(3000)
    await flushPromises()
    expect(wrapper.get('[data-testid="text-smart-search-run"]').text()).toContain(
      'so search is back on Docs embed (Test)'
    )
    await vi.advanceTimersByTimeAsync(9000)
    expect(getAdminSearchConfig).toHaveBeenCalledTimes(2)
    vi.useRealTimers()
  })

  it('says what was refused and that nothing changed', async () => {
    confirm.mockResolvedValue(true)
    putAdminSearchConfig.mockRejectedValue(new ApiError(400, 'no', 'x', { reason: 'probe_failed' }))
    const wrapper = await mountCard()

    await choose(wrapper, 'smart-search-embed-model', '11')

    expect(error).toHaveBeenCalledWith(
      'Other embed (Test) did not answer a test call, so nothing was changed.'
    )
  })

  it('points to the feature switch while AI help is off', async () => {
    getAdminSearchConfig.mockResolvedValue(config({ aiEnabled: false, aiAvailable: false }))
    const wrapper = await mountCard()
    expect(wrapper.get('[data-testid="text-smart-search-ai-status"]').text()).toContain(
      'switched off'
    )
    expect(wrapper.find('[data-testid="link-smart-search-ai-flag"]').exists()).toBe(true)
  })
})
