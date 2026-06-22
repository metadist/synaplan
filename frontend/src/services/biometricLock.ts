/**
 * Optional biometric app lock (Epic 7.2).
 *
 * Gates access to the already-authenticated session behind Face ID / Touch ID /
 * fingerprint. This is a UI lock layered on top of the secure token storage — it
 * does not replace it. The opt-in flag is a non-secret boolean kept in
 * localStorage; the tokens themselves stay in the Keychain/Keystore.
 *
 * Native-only; every function degrades to a safe default on web.
 */
import { isNativeApp } from '@/services/api/nativeRuntime'

const ENABLED_KEY = 'biometric_lock_enabled'

export function isBiometricLockEnabled(): boolean {
  return 'true' === localStorage.getItem(ENABLED_KEY)
}

export function setBiometricLockEnabled(enabled: boolean): void {
  if (enabled) {
    localStorage.setItem(ENABLED_KEY, 'true')
  } else {
    localStorage.removeItem(ENABLED_KEY)
  }
}

/** True only when running natively AND the device has biometrics enrolled. */
export async function isBiometricAvailable(): Promise<boolean> {
  if (!isNativeApp()) {
    return false
  }
  try {
    const { BiometricAuth } = await import('@aparajita/capacitor-biometric-auth')
    const result = await BiometricAuth.checkBiometry()
    return result.isAvailable
  } catch {
    return false
  }
}

/** Prompt the OS biometric dialog. Resolves true on success, false on cancel/fail. */
export async function verifyBiometric(reason: string): Promise<boolean> {
  if (!isNativeApp()) {
    return true
  }
  try {
    const { BiometricAuth } = await import('@aparajita/capacitor-biometric-auth')
    await BiometricAuth.authenticate({
      reason,
      androidTitle: reason,
      cancelTitle: 'Cancel',
      // Allow the device PIN/passcode as a fallback so users can't lock
      // themselves out if biometrics temporarily fail.
      allowDeviceCredential: true,
    })
    return true
  } catch {
    return false
  }
}
