/**
 * SPA-side seam for app-owned native haptics.
 *
 * The native shell (synaplan-apps repo) injects `app/synaplan-native.js` BEFORE
 * the SPA bundle and exposes a tiny imperative API on `window.SynaplanHaptics`
 * that wraps the Capacitor Haptics plugin (Taptic Engine on iOS, vibration on
 * Android). This keeps the public submodule Capacitor-free (same pattern as
 * `nativeServer.ts` → `window.SynaplanServer`).
 *
 * On the plain web build (and any native build without the bootstrap)
 * `window.SynaplanHaptics` is absent, so `triggerHapticImpact()` is a safe no-op.
 */

interface NativeHapticsApi {
  impact: (style?: 'light' | 'medium' | 'heavy') => void
}

function getApi(): NativeHapticsApi | null {
  const api = (globalThis as { SynaplanHaptics?: unknown }).SynaplanHaptics
  if (api && 'object' === typeof api && 'function' === typeof (api as NativeHapticsApi).impact) {
    return api as NativeHapticsApi
  }
  return null
}

/**
 * Fire a single haptic impact. No-op when the native bridge is absent (web).
 * Never throws — haptics are a nice-to-have, never a hard dependency.
 */
export function triggerHapticImpact(style: 'light' | 'medium' | 'heavy' = 'light'): void {
  const api = getApi()
  if (!api) {
    return
  }
  try {
    api.impact(style)
  } catch {
    /* no-op: haptics must never break an interaction */
  }
}
