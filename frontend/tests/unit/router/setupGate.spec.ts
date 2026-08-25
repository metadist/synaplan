import { describe, it, expect, vi } from 'vitest'
import { isSetupWizardRequired, resolveSetupGate, SETUP_ROUTE } from '@/router/setupGate'

const runtimeConfig = vi.fn()
vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => runtimeConfig(),
}))

const navigation = (overrides: Partial<Parameters<typeof resolveSetupGate>[0]> = {}) => ({
  wizardRequired: false,
  routeName: 'chat' as string | symbol | null | undefined,
  isNativeOnboarding: false,
  ...overrides,
})

describe('resolveSetupGate', () => {
  it('sends every route to the wizard while the instance has no administrator', () => {
    expect(resolveSetupGate(navigation({ wizardRequired: true }))).toBe('force')
  })

  it('leaves the wizard itself reachable', () => {
    expect(
      resolveSetupGate(navigation({ wizardRequired: true, routeName: SETUP_ROUTE }))
    ).toBeNull()
  })

  it('does not interfere with a set-up instance', () => {
    expect(resolveSetupGate(navigation())).toBeNull()
  })

  it('releases anyone who lands on the wizard of a set-up instance', () => {
    expect(resolveSetupGate(navigation({ routeName: SETUP_ROUTE }))).toBe('release')
  })

  it('keeps the native onboarding open, because it is where a server gets picked', () => {
    expect(
      resolveSetupGate(
        navigation({ wizardRequired: true, routeName: 'onboarding', isNativeOnboarding: true })
      )
    ).toBeNull()
  })

  it('still forces the wizard on the web onboarding, which cannot switch servers', () => {
    expect(
      resolveSetupGate(
        navigation({ wizardRequired: true, routeName: 'onboarding', isNativeOnboarding: false })
      )
    ).toBe('force')
  })

  it('treats a nameless route like any other route', () => {
    expect(resolveSetupGate(navigation({ wizardRequired: true, routeName: undefined }))).toBe(
      'force'
    )
  })
})

describe('isSetupWizardRequired', () => {
  it('reports the flag the backend sent', () => {
    runtimeConfig.mockReturnValue({ setup: { wizardRequired: true } })

    expect(isSetupWizardRequired()).toBe(true)
  })

  it('never sends a working installation into the wizard when the config is missing', () => {
    runtimeConfig.mockReturnValue({})

    expect(isSetupWizardRequired()).toBe(false)
  })
})
