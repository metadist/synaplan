import { beforeEach, describe, expect, it, vi } from 'vitest'

const getConfigSync = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

import { isModuleConfigured, isModuleGated } from '@/composables/useModuleFeature'

describe('useModuleFeature', () => {
  beforeEach(() => {
    getConfigSync.mockReset()
  })

  it('reads the module state from the runtime config', () => {
    getConfigSync.mockReturnValue({
      modules: {
        whatsapp: { configured: false, gated: true },
        stripe_billing: { configured: true, gated: false },
      },
    })

    expect(isModuleConfigured('whatsapp')).toBe(false)
    expect(isModuleGated('whatsapp')).toBe(true)
    expect(isModuleConfigured('stripe_billing')).toBe(true)
    expect(isModuleGated('stripe_billing')).toBe(false)
  })

  it('treats a backend without the modules key as fully configured and ungated', () => {
    getConfigSync.mockReturnValue({ features: {} })

    expect(isModuleConfigured('whatsapp')).toBe(true)
    expect(isModuleGated('whatsapp')).toBe(false)
  })

  it('treats an unknown module id as configured and ungated', () => {
    getConfigSync.mockReturnValue({ modules: { whatsapp: { configured: false, gated: false } } })

    expect(isModuleConfigured('not_a_module')).toBe(true)
    expect(isModuleGated('not_a_module')).toBe(false)
  })

  it('only trusts explicit booleans', () => {
    getConfigSync.mockReturnValue({
      modules: { whatsapp: { configured: 'no', gated: 'yes' } },
    })

    expect(isModuleConfigured('whatsapp')).toBe(true)
    expect(isModuleGated('whatsapp')).toBe(false)
  })
})
