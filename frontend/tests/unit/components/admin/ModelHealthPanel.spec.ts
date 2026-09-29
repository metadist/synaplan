import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const mockGetStatus = vi.fn()
const mockResetCounters = vi.fn()
const mockConfirm = vi.fn()

vi.mock('@/services/api/adminModelStatusApi', () => ({
  modelStatusApi: {
    getStatus: (...args: unknown[]) => mockGetStatus(...args),
    refresh: vi.fn(),
    setExempt: vi.fn(),
    resetCounters: (...args: unknown[]) => mockResetCounters(...args),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: (...args: unknown[]) => mockConfirm(...args) }),
}))

vi.mock('@iconify/vue', () => ({
  Icon: { template: '<i />' },
}))

const setModelsNeedingAttention = vi.fn()
vi.mock('@/composables/useNavItems', () => ({
  setModelsNeedingAttention: (...args: unknown[]) => setModelsNeedingAttention(...args),
}))

import ModelHealthPanel from '@/components/admin/ModelHealthPanel.vue'

const snapshot = {
  success: true,
  summary: {
    total: 1,
    online: 1,
    degraded: 0,
    offline: 0,
    unconfigured: 0,
    unknown: 0,
    retired: 0,
    needsAttention: 0,
    lastCheck: 1_700_000_000,
    autoDisableEnabled: false,
    monitoringEnabled: true,
  },
  providers: [
    {
      name: 'openai',
      displayName: 'OpenAI',
      needsAttention: 0,
      models: [
        {
          id: 42,
          name: 'GPT-4o',
          providerId: 'gpt-4o',
          capability: 'chat',
          state: 'online',
          reason: '',
          source: 'probe',
          lastCheck: 1_700_000_000,
          lastSuccess: 1_700_000_000,
          lastFailure: 0,
          successes: 4,
          failures: 0,
          errorRatePercent: 0,
          active: true,
          selectable: true,
          autoDisabled: false,
          exemptUntil: 0,
        },
      ],
    },
  ],
  retired: [] as Array<{
    id: number
    name: string
    providerId: string
    capability: string
    provider: string
    providerDisplayName: string
    retiredOn: string
    successorName: string | null
  }>,
}

const withRetired = {
  ...snapshot,
  summary: { ...snapshot.summary, total: 3, retired: 2 },
  retired: [
    {
      id: 92,
      name: 'Claude 3 Haiku',
      providerId: 'claude-3-haiku-20240307',
      capability: 'chat',
      provider: 'anthropic',
      providerDisplayName: 'Anthropic',
      retiredOn: '2026-05-08',
      successorName: 'Claude Haiku 4.5',
    },
    {
      id: 320,
      name: 'Grok TTS',
      providerId: 'grok-tts',
      capability: 'text2sound',
      provider: 'xai',
      providerDisplayName: 'xAI',
      retiredOn: '2026-08-20',
      successorName: null,
    },
  ],
}

function mountView() {
  return mount(ModelHealthPanel)
}

describe('ModelHealthPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockGetStatus.mockResolvedValue(snapshot)
    mockConfirm.mockResolvedValue(true)
    mockResetCounters.mockResolvedValue({ success: true, modelId: 42 })
  })

  it('shows a human task name instead of the raw catalog tag', async () => {
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.get('[data-testid="item-model"]').text()).toContain('Chat / General AI')
    expect(wrapper.get('[data-testid="item-model"]').text()).not.toMatch(/\bchat\b/)
    wrapper.unmount()
  })

  it('keeps the Operate badge in step with the loaded snapshot', async () => {
    mockGetStatus.mockResolvedValue({
      ...snapshot,
      summary: { ...snapshot.summary, offline: 2, online: 0, needsAttention: 2 },
    })
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.get('[data-testid="panel-model-health"]').text()).toContain(
      'Which AI models are currently working'
    )
    expect(setModelsNeedingAttention).toHaveBeenCalledWith(2)
    wrapper.unmount()
  })

  it('asks before clearing the error history', async () => {
    const wrapper = mountView()
    await flushPromises()

    await wrapper.get('[data-testid="btn-reset-counters"]').trigger('click')
    await flushPromises()

    expect(mockConfirm).toHaveBeenCalledOnce()
    expect(mockResetCounters).toHaveBeenCalledWith(42)
    wrapper.unmount()
  })

  it('renders a listing-source model instead of the load-error state', async () => {
    mockGetStatus.mockResolvedValue({
      ...snapshot,
      providers: [
        {
          ...snapshot.providers[0],
          models: [{ ...snapshot.providers[0].models[0], source: 'listing' }],
        },
      ],
    })
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.find('[data-testid="state-load-error"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="item-model"]').text()).toContain('import source listing')
    wrapper.unmount()
  })

  it('lists retired models apart, with their replacement, never as a problem', async () => {
    mockGetStatus.mockResolvedValue(withRetired)
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.get('[data-testid="summary-counts"]').text()).toContain('2 Retired')
    expect(wrapper.findAll('[data-testid="item-model"]')).toHaveLength(1)

    const section = wrapper.get('[data-testid="section-retired"]')
    expect(section.attributes('data-open')).toBe('false')
    await wrapper.get('[data-testid="btn-retired"]').trigger('click')

    const items = wrapper.findAll('[data-testid="item-retired-model"]')
    expect(items).toHaveLength(2)
    expect(items[0].text()).toContain('Claude 3 Haiku')
    expect(items[0].text()).toContain('Replaced by Claude Haiku 4.5')
    expect(items[1].text()).toContain('No replacement')
    expect(setModelsNeedingAttention).toHaveBeenCalledWith(0)
    wrapper.unmount()
  })

  it('hides retired models when only problems are shown', async () => {
    mockGetStatus.mockResolvedValue(withRetired)
    const wrapper = mountView()
    await flushPromises()

    await wrapper.get('[data-testid="filter-only-problems"]').setValue(true)

    expect(wrapper.find('[data-testid="section-retired"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="state-empty"]').exists()).toBe(true)
    wrapper.unmount()
  })
})
