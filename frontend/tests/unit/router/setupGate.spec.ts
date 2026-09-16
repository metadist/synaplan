import { beforeEach, describe, expect, it, vi } from 'vitest'

const runtimeConfig = vi.fn()
const getConfig = vi.fn()
const getSetupState = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => runtimeConfig(),
  getConfig: () => getConfig(),
}))

vi.mock('@/services/api/setupApi', () => ({
  getSetupState: () => getSetupState(),
}))

const {
  ensureWizardRequired,
  invalidateSetupWizardRequired,
  isSetupRecheckRoute,
  isSetupWizardEnabled,
  isSetupWizardRequired,
  resolveSetupGate,
  SETUP_ROUTE,
} = await import('@/router/setupGate')

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
  beforeEach(() => {
    invalidateSetupWizardRequired()
    runtimeConfig.mockReturnValue({})
  })

  it('reports the flag the backend sent', () => {
    runtimeConfig.mockReturnValue({ setup: { wizardRequired: true } })

    expect(isSetupWizardRequired()).toBe(true)
  })

  it('never sends a working installation into the wizard when the config is missing', () => {
    runtimeConfig.mockReturnValue({})

    expect(isSetupWizardRequired()).toBe(false)
  })

  /**
   * The SSO/OIDC deployment: `SETUP_WIZARD_ENABLED=false` means an empty
   * installation is the steady state, so even a backend that reports
   * `wizardRequired` must not produce a wizard here.
   */
  it('stays out of the way when the operator switched the wizard off', () => {
    runtimeConfig.mockReturnValue({ setup: { wizardEnabled: false, wizardRequired: true } })

    expect(isSetupWizardRequired()).toBe(false)
  })
})

describe('isSetupWizardEnabled', () => {
  beforeEach(() => {
    invalidateSetupWizardRequired()
  })

  it('reports the operator kill switch', () => {
    runtimeConfig.mockReturnValue({ setup: { wizardEnabled: false } })

    expect(isSetupWizardEnabled()).toBe(false)
  })

  it('assumes the wizard exists when an older backend does not report the flag', () => {
    runtimeConfig.mockReturnValue({ setup: {} })

    expect(isSetupWizardEnabled()).toBe(true)
  })
})

describe('isSetupRecheckRoute', () => {
  it('re-reads the runtime config on the pages a reset operator actually opens', () => {
    expect(isSetupRecheckRoute('chat')).toBe(true)
    expect(isSetupRecheckRoute('login')).toBe(true)
    expect(isSetupRecheckRoute(SETUP_ROUTE)).toBe(true)
  })

  it('does not re-read on ordinary in-app navigation', () => {
    expect(isSetupRecheckRoute('files')).toBe(false)
    expect(isSetupRecheckRoute(undefined)).toBe(false)
  })
})

describe('ensureWizardRequired', () => {
  beforeEach(() => {
    invalidateSetupWizardRequired()
    getConfig.mockResolvedValue({})
    runtimeConfig.mockReturnValue({})
    getSetupState.mockReset()
  })

  it('trusts runtime config when it already says the wizard is required', async () => {
    runtimeConfig.mockReturnValue({ setup: { wizardRequired: true } })

    await expect(ensureWizardRequired()).resolves.toBe(true)
    expect(getSetupState).not.toHaveBeenCalled()
  })

  /**
   * The cost of the opposite default: `/` is an entry route, so probing there
   * would put an awaited round trip in front of the app's main view on every
   * installation — for a state that only the dev-only `app:setup:reset` can
   * change, and that 503 SETUP_REQUIRED catches anyway.
   */
  it('trusts a loaded runtime config that reports a set-up installation', async () => {
    runtimeConfig.mockReturnValue({ setup: { wizardRequired: false } })

    await expect(ensureWizardRequired({ fresh: true })).resolves.toBe(false)
    expect(getSetupState).not.toHaveBeenCalled()
  })

  /**
   * No `setup` object at all means the runtime config never loaded, so there is
   * nothing to trust and the dedicated endpoint is worth the request.
   */
  it('asks /setup/state when the runtime config carries no setup state', async () => {
    runtimeConfig.mockReturnValue({})
    getSetupState.mockResolvedValue({ wizardRequired: true })

    await expect(ensureWizardRequired({ fresh: true })).resolves.toBe(true)
    expect(getSetupState).toHaveBeenCalled()
  })

  it('asks /setup/state when the caller explicitly probes', async () => {
    runtimeConfig.mockReturnValue({ setup: { wizardRequired: false } })
    getSetupState.mockResolvedValue({ wizardRequired: true })

    await expect(ensureWizardRequired({ fresh: true, probe: true })).resolves.toBe(true)
    expect(isSetupWizardRequired()).toBe(true)
  })

  it('does not reopen the wizard when the dedicated endpoint agrees it is done', async () => {
    runtimeConfig.mockReturnValue({ setup: { wizardRequired: false } })
    getSetupState.mockResolvedValue({ wizardRequired: false })

    await expect(ensureWizardRequired({ fresh: true, probe: true })).resolves.toBe(false)
    expect(isSetupWizardRequired()).toBe(false)
  })

  it('reuses the in-memory answer until the caller asks for a fresh probe', async () => {
    runtimeConfig.mockReturnValue({ setup: { wizardRequired: false } })
    getSetupState.mockResolvedValue({ wizardRequired: false })

    await ensureWizardRequired({ fresh: true, probe: true })
    getSetupState.mockClear()

    await expect(ensureWizardRequired()).resolves.toBe(false)
    expect(getSetupState).not.toHaveBeenCalled()
  })

  it('notices a CLI reset that happened after a previous false answer', async () => {
    runtimeConfig.mockReturnValue({ setup: { wizardRequired: false } })
    getSetupState.mockResolvedValue({ wizardRequired: false })
    await ensureWizardRequired({ fresh: true, probe: true })

    getSetupState.mockResolvedValue({ wizardRequired: true })
    await expect(ensureWizardRequired({ fresh: true, probe: true })).resolves.toBe(true)
  })

  it('keeps a working installation out of the wizard when /setup/state cannot be reached', async () => {
    runtimeConfig.mockReturnValue({ setup: { wizardRequired: false } })
    getSetupState.mockRejectedValue(new Error('offline'))

    await expect(ensureWizardRequired({ fresh: true, probe: true })).resolves.toBe(false)
  })

  /**
   * An OIDC instance runs for years with the wizard switched off. Asking
   * `/setup/state` on every entry navigation would be noise for an answer that
   * cannot change without a redeploy.
   */
  it('never probes the server once the wizard is switched off by the operator', async () => {
    runtimeConfig.mockReturnValue({ setup: { wizardEnabled: false } })

    await expect(ensureWizardRequired({ fresh: true, probe: true })).resolves.toBe(false)
    expect(getSetupState).not.toHaveBeenCalled()
  })
})
