/**
 * Shared biometric-lock state + lifecycle (Epic 7.2).
 *
 * `locked` drives the full-screen BiometricLockScreen overlay. We lock on first
 * launch (when enabled) and re-lock whenever the app leaves the foreground, so a
 * resume always requires biometrics. A `verifying` guard prevents the OS dialog's
 * own foreground/background churn from re-locking mid-prompt.
 */
import { ref } from 'vue'
import { isNativeApp } from '@/services/api/nativeRuntime'
import {
  isBiometricLockEnabled,
  isBiometricAvailable,
  verifyBiometric,
} from '@/services/biometricLock'

const locked = ref(false)
let initialized = false
let verifying = false

export function useBiometricLock() {
  return { locked, unlock }
}

export async function initBiometricLock(): Promise<void> {
  if (initialized || !isNativeApp()) {
    return
  }
  initialized = true

  if (isBiometricLockEnabled() && (await isBiometricAvailable())) {
    locked.value = true
    void promptUnlock()
  }

  const { App: CapacitorApp } = await import('@capacitor/app')
  await CapacitorApp.addListener('appStateChange', ({ isActive }) => {
    if (!isActive) {
      // Re-arm the lock when backgrounding — unless a prompt is in flight.
      if (!verifying && isBiometricLockEnabled()) {
        locked.value = true
      }
      return
    }
    if (locked.value) {
      void promptUnlock()
    }
  })
}

async function unlock(): Promise<void> {
  await promptUnlock()
}

async function promptUnlock(): Promise<void> {
  if (verifying) {
    return
  }
  verifying = true
  try {
    const ok = await verifyBiometric('Unlock Synaplan')
    if (ok) {
      locked.value = false
    }
  } finally {
    verifying = false
  }
}
