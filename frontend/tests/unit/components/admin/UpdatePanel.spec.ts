import { describe, it, expect, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import UpdatePanel from '@/components/admin/UpdatePanel.vue'
import type { UpdateStatus } from '@/services/api/updates'

const storeState = vi.hoisted(() => ({
  status: null as UpdateStatus | null,
  loading: false,
  checkEnabled: true,
  latestVersion: null as string | null,
  severity: 'normal' as UpdateStatus['severity'],
  ensureLoaded: vi.fn(),
  checkNow: vi.fn(),
  dismissLatest: vi.fn(),
}))

vi.mock('@/stores/updates', () => ({
  useUpdatesStore: () => storeState,
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

vi.mock('@/composables/useDateFormat', () => ({
  useDateFormat: () => ({
    formatDate: (date: Date) => date.toISOString().slice(0, 10),
    formatRelativeTime: () => 'just now',
  }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success: vi.fn(), error: vi.fn() }),
}))

const mountOptions = {
  global: {
    stubs: { Icon: true, UpdateCheckToggle: true },
    mocks: { $t: (key: string) => key },
  },
}

function statusPayload(overrides: Partial<UpdateStatus> = {}): UpdateStatus {
  return {
    currentVersion: '4.0.12',
    latestVersion: '4.0.13',
    updateAvailable: true,
    notesUrl: 'https://example.test/releases/tag/v4.0.13',
    severity: 'normal',
    releasedAt: '2026-08-10T09:00:00Z',
    lastCheckedAt: '2026-08-05T10:24:22+00:00',
    lastError: null,
    dismissedVersion: null,
    checkEnabled: true,
    platform: 'selfhost',
    guideUrl: 'https://example.test/docs/UPDATE_SELFHOST.md',
    ...overrides,
  }
}

describe('UpdatePanel', () => {
  beforeEach(() => {
    storeState.status = null
    storeState.loading = false
    storeState.checkEnabled = true
    storeState.latestVersion = null
    storeState.severity = 'normal'
    storeState.ensureLoaded.mockReset()
    storeState.checkNow.mockReset()
    storeState.dismissLatest.mockReset()
  })

  it('spins only while the status is actually being fetched', async () => {
    storeState.loading = true
    const wrapper = mount(UpdatePanel, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="state-admin-updates-loading"]').exists()).toBe(true)
  })

  // A failed read used to leave the spinner running forever, which hid the whole
  // card — including the switch — behind something that looked like progress.
  it('explains an unreadable status instead of spinning forever', async () => {
    storeState.loading = false
    storeState.status = null
    const wrapper = mount(UpdatePanel, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="state-admin-updates-loading"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="state-admin-updates-unavailable"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('updates.panel.statusUnavailable')
  })

  it('keeps "check now" usable so the admin can recover', async () => {
    storeState.loading = false
    storeState.status = null
    const wrapper = mount(UpdatePanel, mountOptions)
    await flushPromises()

    const button = wrapper.get('[data-testid="btn-admin-updates-check"]')
    expect(button.attributes('disabled')).toBeUndefined()

    await button.trigger('click')
    expect(storeState.checkNow).toHaveBeenCalledTimes(1)
  })

  it('shows the versions once the status is available', async () => {
    storeState.status = statusPayload()
    storeState.latestVersion = '4.0.13'
    const wrapper = mount(UpdatePanel, mountOptions)
    await flushPromises()

    expect(wrapper.find('[data-testid="state-admin-updates-loading"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="state-admin-updates-unavailable"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="text-admin-updates-current"]').text()).toBe('4.0.12')
    expect(wrapper.get('[data-testid="text-admin-updates-latest"]').text()).toBe('4.0.13')
  })
})
