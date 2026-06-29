/**
 * OTA live-update integration (Epic 8.1, Capgo).
 *
 * The native shell ships with a builtin web bundle and — once registered with
 * the update server — can pull CONFORMING web-asset fixes over the air without a
 * store review (policy: synaplan-apps/docs/OTA_POLICY.md). Behavior/payment logic
 * must never be OTA'd.
 *
 * Update *delivery* is fully handled by the Capgo plugin in auto-update mode
 * (configured in synaplan-apps/capacitor.config.ts): it downloads in the
 * background and applies the new bundle on the next cold start. The ONLY runtime
 * responsibility of the web app is to confirm, on every launch, that the active
 * bundle booted successfully — otherwise Capgo auto-reverts to the previous good
 * bundle after `appReadyTimeout`. That confirmation is `notifyAppReady()`.
 *
 * Web is unaffected: the import is dynamic and guarded by isNativeApp(), so the
 * Capgo runtime never loads in the browser bundle.
 */
import { isNativeApp } from '@/services/api/nativeRuntime'

let initialized = false

/**
 * Confirm the active OTA bundle is healthy. No-op on web or if already called.
 * Safe to call on the builtin bundle (it simply confirms the current bundle).
 */
export async function initOtaUpdates(): Promise<void> {
  if (initialized || !isNativeApp()) {
    return
  }
  initialized = true

  try {
    const { CapacitorUpdater } = await import('@capgo/capacitor-updater')
    await CapacitorUpdater.notifyAppReady()
  } catch (err) {
    // A failed confirmation must not crash the app; worst case Capgo rolls the
    // bundle back, which is the intended safety behavior.
    console.error('OTA notifyAppReady failed:', err)
  }
}
