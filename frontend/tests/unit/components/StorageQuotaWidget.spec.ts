import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import StorageQuotaWidget from '@/components/StorageQuotaWidget.vue'

const runtime = vi.hoisted(() => ({ planLimits: false }))

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    billing: {
      enabled: false,
      get planLimits() {
        return runtime.planLimits
      },
    },
  }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ isAdmin: false }),
}))

vi.mock('@/services/api/nativeServer', () => ({
  isPurchaseAllowed: () => true,
}))

vi.mock('@/services/filesService', () => ({
  getStorageStats: vi.fn().mockResolvedValue({
    user_level: 'NEW',
    storage: {
      usage_formatted: '0 B',
      limit_formatted: '0 B',
      remaining_formatted: '0 B',
      percentage: 0,
      unlimited: false,
    },
  }),
}))

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  missingWarn: false,
  fallbackWarn: false,
  messages: {
    en: {
      storage: {
        title: 'Storage Quota',
        plan: 'Plan',
        unlimited: 'Unlimited',
        plans: { NEW: 'Free' },
      },
    },
  },
})

const mountWidget = async () => {
  const wrapper = mount(StorageQuotaWidget, {
    global: { plugins: [i18n], stubs: { Icon: true } },
  })
  await flushPromises()
  return wrapper
}

describe('StorageQuotaWidget without billing', () => {
  beforeEach(() => {
    runtime.planLimits = false
  })

  it('shows unlimited storage while the plan limits are off', async () => {
    const wrapper = await mountWidget()

    expect(wrapper.text()).toContain('Unlimited')
  })

  it('shows the plan quota once the plan limits apply', async () => {
    runtime.planLimits = true
    const wrapper = await mountWidget()

    expect(wrapper.text()).not.toContain('Unlimited')
    expect(wrapper.text()).toContain('0 B / 0 B')
  })
})
