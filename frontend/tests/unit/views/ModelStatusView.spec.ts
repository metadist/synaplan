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

import ModelStatusView from '@/views/ModelStatusView.vue'

const snapshot = {
  success: true,
  summary: {
    total: 1,
    online: 1,
    degraded: 0,
    offline: 0,
    unconfigured: 0,
    unknown: 0,
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
}

function mountView() {
  return mount(ModelStatusView, {
    global: {
      stubs: { MainLayout: { template: '<div><slot /></div>' } },
    },
  })
}

describe('ModelStatusView', () => {
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

  it('asks before clearing the error history', async () => {
    const wrapper = mountView()
    await flushPromises()

    await wrapper.get('[data-testid="btn-reset-counters"]').trigger('click')
    await flushPromises()

    expect(mockConfirm).toHaveBeenCalledOnce()
    expect(mockResetCounters).toHaveBeenCalledWith(42)
    wrapper.unmount()
  })
})
