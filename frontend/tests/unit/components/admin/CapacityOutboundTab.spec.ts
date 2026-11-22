import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ApiError } from '@/services/api/httpClient'
import type { OutboundReport } from '@/services/api/capacityApi'

const mockOutbound = vi.fn()
const mockAllow = vi.fn()
const mockRemove = vi.fn()
const mockConfirm = vi.fn()
const mockSuccess = vi.fn()
const mockError = vi.fn()

vi.mock('@/services/api/capacityApi', () => ({
  capacityApi: {
    outbound: (...args: unknown[]) => mockOutbound(...args),
    allow: (...args: unknown[]) => mockAllow(...args),
    removeAllow: (...args: unknown[]) => mockRemove(...args),
  },
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: mockSuccess, error: mockError }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ confirm: (...args: unknown[]) => mockConfirm(...args) }),
}))

import CapacityOutboundTab from '@/components/admin/capacity/CapacityOutboundTab.vue'

const report = (mode: OutboundReport['mode']): OutboundReport => ({
  success: true,
  served_by: 'web-1',
  generated_at: '2026-11-22T10:00:00+00:00',
  mode,
  destinations: [
    {
      destination: 'api.example.org',
      requests: 4,
      failures: 0,
      refused: 0,
      p95_ms: 120,
      state: 'not_listed',
      rule: null,
      allow_rule: 'api.example.org',
    },
    {
      destination: 'hooks.example.net',
      requests: 2,
      failures: 1,
      refused: 0,
      p95_ms: 300,
      state: 'listed',
      rule: '*.example.net',
      allow_rule: null,
    },
    {
      destination: 'typed',
      requests: 1,
      failures: 0,
      refused: 0,
      p95_ms: 40,
      state: 'typed',
      rule: null,
      allow_rule: null,
    },
  ],
})

const mountTab = () =>
  mount(CapacityOutboundTab, {
    global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
  })

const row = (wrapper: ReturnType<typeof mountTab>, destination: string) =>
  wrapper.find(`[data-testid="capacity-outbound-row"][data-destination="${destination}"]`)

describe('CapacityOutboundTab', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockAllow.mockResolvedValue({ success: true, rules: [] })
    mockRemove.mockResolvedValue({ success: true, rules: [] })
  })

  it('says a destination nobody listed would be refused in report mode', async () => {
    mockOutbound.mockResolvedValue(report('report'))
    const wrapper = mountTab()
    await flushPromises()

    const unlisted = row(wrapper, 'api.example.org')
    expect(unlisted.find('[data-testid="capacity-outbound-state"]').text()).toBe('Would be refused')
    expect(unlisted.find('[data-testid="btn-capacity-outbound-allow"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('never offers Allow for an address someone typed', async () => {
    mockOutbound.mockResolvedValue(report('report'))
    const wrapper = mountTab()
    await flushPromises()

    const typed = row(wrapper, 'typed')
    expect(typed.find('[data-testid="btn-capacity-outbound-allow"]').exists()).toBe(false)
    expect(typed.find('[data-testid="btn-capacity-outbound-remove"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('allows a destination, shows it as listed, and reads the report again', async () => {
    mockOutbound.mockResolvedValue(report('report'))
    const wrapper = mountTab()
    await flushPromises()
    expect(mockOutbound).toHaveBeenCalledTimes(1)

    await row(wrapper, 'api.example.org')
      .find('[data-testid="btn-capacity-outbound-allow"]')
      .trigger('click')
    await flushPromises()

    expect(mockAllow).toHaveBeenCalledWith('api.example.org')
    expect(mockSuccess).toHaveBeenCalled()
    expect(mockOutbound).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('asks before removing a rule while the filter only reports, and removes it on confirm', async () => {
    mockOutbound.mockResolvedValue(report('report'))
    mockConfirm.mockResolvedValue(true)
    const wrapper = mountTab()
    await flushPromises()

    await row(wrapper, 'hooks.example.net')
      .find('[data-testid="btn-capacity-outbound-remove"]')
      .trigger('click')
    await flushPromises()

    expect(mockConfirm).toHaveBeenCalledWith(expect.objectContaining({ danger: false }))
    expect(mockRemove).toHaveBeenCalledWith('*.example.net')
    wrapper.unmount()
  })

  it('keeps the rule when removal is cancelled while the filter only reports', async () => {
    mockOutbound.mockResolvedValue(report('report'))
    mockConfirm.mockResolvedValue(false)
    const wrapper = mountTab()
    await flushPromises()

    await row(wrapper, 'hooks.example.net')
      .find('[data-testid="btn-capacity-outbound-remove"]')
      .trigger('click')
    await flushPromises()

    expect(mockRemove).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('asks before removing a rule while the filter refuses, and keeps it on cancel', async () => {
    mockOutbound.mockResolvedValue(report('enforce'))
    mockConfirm.mockResolvedValue(false)
    const wrapper = mountTab()
    await flushPromises()

    await row(wrapper, 'hooks.example.net')
      .find('[data-testid="btn-capacity-outbound-remove"]')
      .trigger('click')
    await flushPromises()

    expect(mockConfirm).toHaveBeenCalledTimes(1)
    expect(mockRemove).not.toHaveBeenCalled()
    expect(
      row(wrapper, 'hooks.example.net')
        .find('[data-testid="btn-capacity-outbound-remove"]')
        .exists()
    ).toBe(true)
    wrapper.unmount()
  })

  it('explains a rule the server rejects and keeps the row as it was', async () => {
    mockOutbound.mockResolvedValue(report('report'))
    mockAllow.mockRejectedValue(new ApiError(422, 'invalid_rule'))
    const wrapper = mountTab()
    await flushPromises()

    await row(wrapper, 'api.example.org')
      .find('[data-testid="btn-capacity-outbound-allow"]')
      .trigger('click')
    await flushPromises()

    expect(mockError).toHaveBeenCalledWith('That destination cannot go on the allow list.')
    expect(row(wrapper, 'api.example.org').attributes('data-state')).toBe('not_listed')
    wrapper.unmount()
  })

  it('says nothing is recorded while the filter is off', async () => {
    mockOutbound.mockResolvedValue({ ...report('off'), destinations: [] })
    const wrapper = mountTab()
    await flushPromises()

    expect(wrapper.find('[data-testid="state-capacity-outbound-empty"]').text()).toBe(
      'Outbound calls are not recorded while the outbound filter is off.'
    )
    wrapper.unmount()
  })

  it('shows the unavailable state when the shared cache does not answer', async () => {
    mockOutbound.mockRejectedValue(new ApiError(503, 'unavailable'))
    const wrapper = mountTab()
    await flushPromises()

    expect(wrapper.find('[data-testid="state-capacity-unavailable"]').exists()).toBe(true)
    wrapper.unmount()
  })
})
