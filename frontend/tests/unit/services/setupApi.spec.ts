import { beforeEach, describe, expect, it, vi } from 'vitest'

const httpClient = vi.fn()
const setNativeTokens = vi.fn()
const setSessionHint = vi.fn()
const adoptSession = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  httpClient: (...args: unknown[]) => httpClient(...args),
}))

vi.mock('@/services/api/nativeAuth', () => ({
  setNativeTokens: (...args: unknown[]) => setNativeTokens(...args),
}))

vi.mock('@/services/sessionHint', () => ({
  setSessionHint: () => setSessionHint(),
}))

vi.mock('@/services/authService', () => ({
  authService: { adoptSession: (...args: unknown[]) => adoptSession(...args) },
}))

const invalidateSetupWizardRequired = vi.fn()
vi.mock('@/router/setupGate', () => ({
  invalidateSetupWizardRequired: () => invalidateSetupWizardRequired(),
}))

const { createFirstAdmin, completeSetup } = await import('@/services/api/setupApi')

const adminResult = {
  success: true,
  user: { id: 1, email: 'admin@example.com', level: 'ADMIN', isAdmin: true },
}

describe('setupApi.createFirstAdmin', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    httpClient.mockResolvedValue(adminResult)
  })

  /**
   * The regression this guards: without the hint, `refreshAccessToken()`
   * short-circuits and the first 401 after the five-minute access token expires
   * logs the administrator out in the middle of the wizard.
   */
  it('records the session hint so the refresh path stays open during the wizard', async () => {
    await createFirstAdmin('admin@example.com', 'Sup3rSecret')

    expect(setSessionHint).toHaveBeenCalled()
  })

  it('adopts the signed-in administrator so the SPA does not wait for /auth/me', async () => {
    await createFirstAdmin('admin@example.com', 'Sup3rSecret')

    expect(adoptSession).toHaveBeenCalledWith({
      id: 1,
      email: 'admin@example.com',
      level: 'ADMIN',
      isAdmin: true,
      emailVerified: true,
    })
  })

  it('persists the Bearer tokens the native shell authenticates with', async () => {
    const tokens = { accessToken: 'a', refreshToken: 'r', tokenType: 'Bearer', expiresIn: 300 }
    httpClient.mockResolvedValue({ ...adminResult, tokens })

    await createFirstAdmin('admin@example.com', 'Sup3rSecret')

    expect(setNativeTokens).toHaveBeenCalledWith(tokens)
  })

  it('tolerates the web response, which carries no tokens at all', async () => {
    await createFirstAdmin('admin@example.com', 'Sup3rSecret')

    expect(setNativeTokens).toHaveBeenCalledWith(undefined)
  })

  it('adopts nothing when the server refuses the administrator', async () => {
    httpClient.mockRejectedValue(new Error('SETUP_ALREADY_COMPLETED'))

    await expect(createFirstAdmin('admin@example.com', 'Sup3rSecret')).rejects.toThrow()
    expect(setSessionHint).not.toHaveBeenCalled()
    expect(setNativeTokens).not.toHaveBeenCalled()
    expect(adoptSession).not.toHaveBeenCalled()
  })

  it('sends the access policy as the signed-in administrator', async () => {
    httpClient.mockResolvedValue({
      success: true,
      registrationEnabled: false,
      guestChatEnabled: false,
    })

    await completeSetup(false, false)

    expect(invalidateSetupWizardRequired).toHaveBeenCalled()
    const [endpoint, options] = httpClient.mock.calls[0] as [string, Record<string, unknown>]
    expect(endpoint).toBe('/api/v1/setup/complete')
    expect(options.skipAuth).toBeUndefined()
  })
})
