/**
 * Shared biometric-lock state + lifecycle (Epic 7.2).
 *
 * `locked` drives the full-screen BiometricLockScreen overlay. We lock on first
 * launch (when enabled) and whenever the app leaves the foreground, so the app
 * switcher never shows the session behind the lock. A `verifying` guard prevents
 * the OS dialog's own foreground/background churn from re-locking mid-prompt.
 *
 * Coming back does not always mean a new biometric prompt: opening the system
 * file/photo picker, the camera or a share sheet backgrounds the WebView even
 * though the user never intentionally left, and prompting on every attachment
 * made the lock unusable. A short excursion therefore lifts the lock silently,
 * mirroring the grace period `nativeLifecycle.ts` applies to session re-checks.
 */
import { ref } from 'vue'
import { isNativeApp } from '@/services/api/nativeRuntime'
import {
  isBiometricLockEnabled,
  isBiometricAvailable,
  verifyBiometric,
} from '@/services/biometricLock'

/**
 * How long the app may stay in the background before a return costs a prompt.
 * Longer than the 30s in `nativeLifecycle.ts` on purpose: picking a photo out of
 * a large library regularly takes longer than half a minute, and that excursion
 * is exactly what this window exists for.
 */
const SILENT_UNLOCK_WITHIN_MS = 60_000

const locked = ref(false)
let initialized = false
let verifying = false
/**
 * Deadline until which a resume may skip the prompt, or 0 for none. Only a lock
 * that backgrounding itself armed gets one — a lock that was already up (cold
 * start, or a prompt the user cancelled) must never be lifted by leaving and
 * coming straight back.
 */
let silentUnlockUntil = 0

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
        silentUnlockUntil = locked.value ? 0 : Date.now() + SILENT_UNLOCK_WITHIN_MS
        locked.value = true
      }
      return
    }
    if (!locked.value) {
      return
    }
    const withinGrace = Date.now() < silentUnlockUntil
    silentUnlockUntil = 0
    if (withinGrace) {
      locked.value = false
      return
    }
    void promptUnlock()
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
    const ok = await verifyBiometric()
    if (ok) {
      locked.value = false
    }
  } finally {
    verifying = false
  }
}
