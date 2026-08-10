import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

/**
 * MOBILE-APP SEAM (Epic 7.2): the biometric lock has to survive the system file
 * picker. Opening it backgrounds the WebView, and re-prompting on every return
 * made attaching a file unusable. These specs pin both halves of the deal: a
 * short excursion costs nothing, a real absence still costs a prompt, and a lock
 * that was already up can never be lifted by stepping out and back in.
 */

const mockIsBiometricLockEnabled = vi.fn(() => true)
const mockIsBiometricAvailable = vi.fn().mockResolvedValue(true)
const mockVerifyBiometric = vi.fn().mockResolvedValue(true)

vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => true,
}))

vi.mock('@/services/biometricLock', () => ({
  isBiometricLockEnabled: () => mockIsBiometricLockEnabled(),
  isBiometricAvailable: () => mockIsBiometricAvailable(),
  verifyBiometric: () => mockVerifyBiometric(),
}))

type StateHandler = (state: { isActive: boolean }) => void

let appStateHandler: StateHandler | null = null

vi.mock('@capacitor/app', () => ({
  App: {
    addListener: (_event: string, handler: StateHandler) => {
      appStateHandler = handler
      return Promise.resolve({ remove: () => Promise.resolve() })
    },
  },
}))

const NOW = 1_700_000_000_000

/**
 * The composable keeps its lock state in module scope, so every case needs a
 * fresh module registry to start from a known state.
 */
async function bootLock() {
  vi.resetModules()
  appStateHandler = null
  const mod = await import('@/composables/useBiometricLock')
  await mod.initBiometricLock()
  // Settle the cold-start prompt so each case starts from a resolved state.
  await Promise.resolve()
  await Promise.resolve()
  return mod
}

/** Leave the app, let `awayMs` pass, come back. */
async function leaveAndReturn(awayMs: number): Promise<void> {
  appStateHandler?.({ isActive: false })
  vi.spyOn(Date, 'now').mockReturnValue(NOW + awayMs)
  appStateHandler?.({ isActive: true })
  await Promise.resolve()
  await Promise.resolve()
}

describe('useBiometricLock — grace period around native excursions', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockIsBiometricLockEnabled.mockReturnValue(true)
    mockIsBiometricAvailable.mockResolvedValue(true)
    mockVerifyBiometric.mockResolvedValue(true)
    vi.spyOn(Date, 'now').mockReturnValue(NOW)
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('locks and prompts on a cold start', async () => {
    const { useBiometricLock } = await bootLock()

    expect(useBiometricLock().locked.value).toBe(false)
    expect(mockVerifyBiometric).toHaveBeenCalledTimes(1)
  })

  it('lifts the lock without a prompt after a short excursion', async () => {
    const { useBiometricLock } = await bootLock()
    mockVerifyBiometric.mockClear()

    await leaveAndReturn(5_000)

    expect(useBiometricLock().locked.value).toBe(false)
    expect(mockVerifyBiometric).not.toHaveBeenCalled()
  })

  it('still hides the session while the app is in the background', async () => {
    const { useBiometricLock } = await bootLock()

    appStateHandler?.({ isActive: false })

    // The app-switcher snapshot must show the lock screen, not the session.
    expect(useBiometricLock().locked.value).toBe(true)
  })

  it('prompts again after a real absence', async () => {
    const { useBiometricLock } = await bootLock()
    mockVerifyBiometric.mockClear()

    await leaveAndReturn(120_000)

    expect(mockVerifyBiometric).toHaveBeenCalledTimes(1)
    expect(useBiometricLock().locked.value).toBe(false)
  })

  it('never lifts a lock the user failed to unlock, however short the excursion', async () => {
    // Cold start where the user cancels the prompt: the lock stays up.
    mockVerifyBiometric.mockResolvedValue(false)
    const { useBiometricLock } = await bootLock()
    expect(useBiometricLock().locked.value).toBe(true)

    mockVerifyBiometric.mockClear()
    await leaveAndReturn(1_000)

    // Stepping out and back in is not a way around the lock.
    expect(useBiometricLock().locked.value).toBe(true)
    expect(mockVerifyBiometric).toHaveBeenCalledTimes(1)
  })

  it('does nothing at all when the lock is switched off', async () => {
    mockIsBiometricLockEnabled.mockReturnValue(false)
    const { useBiometricLock } = await bootLock()

    await leaveAndReturn(120_000)

    expect(useBiometricLock().locked.value).toBe(false)
    expect(mockVerifyBiometric).not.toHaveBeenCalled()
  })
})
