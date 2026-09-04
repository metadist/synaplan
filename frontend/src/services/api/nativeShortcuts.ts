/**
 * SPA-side seam for iOS App Shortcuts.
 *
 * The native shell (synaplan-apps) injects `app/synaplan-native.js` BEFORE the
 * SPA bundle and exposes `window.SynaplanShortcuts`. That API wraps the
 * app-owned Capacitor plugin so this submodule stays Capacitor-free — the same
 * pattern as `nativeHaptics.ts` / `nativeServer.ts`.
 *
 * MOBILE-APP SEAM: every accessor is a no-op when the bridge is absent (plain
 * web build, Android, or an older app binary). Web/self-host behaviour is
 * unchanged.
 */

export type ShortcutActionName = 'open' | 'dictate' | 'photo'

export interface ShortcutActionPayload {
  action: ShortcutActionName
  token: string
}

interface NativeShortcutsApi {
  consumePending: () => Promise<ShortcutActionPayload[]>
  subscribe: (fn: (payload: ShortcutActionPayload) => void) => () => void
}

const KNOWN_ACTIONS = new Set<ShortcutActionName>(['open', 'dictate', 'photo'])

function getApi(): NativeShortcutsApi | null {
  const api = (globalThis as { SynaplanShortcuts?: unknown }).SynaplanShortcuts
  if (
    api &&
    'object' === typeof api &&
    'function' === typeof (api as NativeShortcutsApi).consumePending &&
    'function' === typeof (api as NativeShortcutsApi).subscribe
  ) {
    return api as NativeShortcutsApi
  }
  return null
}

function normalizePayload(raw: unknown): ShortcutActionPayload | null {
  if (!raw || 'object' !== typeof raw) {
    return null
  }
  const action = (raw as { action?: unknown }).action
  if ('string' !== typeof action || !KNOWN_ACTIONS.has(action as ShortcutActionName)) {
    return null
  }
  const token = (raw as { token?: unknown }).token
  return {
    action: action as ShortcutActionName,
    token: 'string' === typeof token ? token : '',
  }
}

/** True when the app-owned Shortcuts bridge is present. */
export function isShortcutBridgeAvailable(): boolean {
  return null !== getApi()
}

/**
 * Pull (and clear) any Shortcuts action that arrived before the SPA mounted.
 * Returns the first action this build understands, or null.
 *
 * The queue is scanned rather than only its head: an action added by a newer
 * app binary must not swallow the tap the user actually made.
 */
export async function consumePendingShortcut(): Promise<ShortcutActionPayload | null> {
  const api = getApi()
  if (!api) {
    return null
  }
  try {
    const pending = await api.consumePending()
    if (!Array.isArray(pending)) {
      return null
    }
    for (const raw of pending) {
      const payload = normalizePayload(raw)
      if (payload) {
        return payload
      }
    }
    return null
  } catch {
    return null
  }
}

/**
 * Listen for live Shortcuts taps while the app is already open.
 * Returns an unsubscribe function. No-op when the bridge is absent.
 */
export function onShortcutAction(cb: (payload: ShortcutActionPayload) => void): () => void {
  const api = getApi()
  if (!api) {
    return () => {}
  }
  try {
    return api.subscribe((raw) => {
      const payload = normalizePayload(raw)
      if (payload) {
        cb(payload)
      }
    })
  } catch {
    return () => {}
  }
}

/**
 * Route dictate/photo Shortcuts onto the chat surface. `open` leaves the
 * current screen alone. No-op on web.
 *
 * MOBILE-APP SEAM: the listener does not consume the pending action — ChatView
 * owns that so the composer is mounted before dictation/camera run.
 */
export function initNativeShortcuts(navigateToChat: () => void): () => void {
  if (!isShortcutBridgeAvailable()) {
    return () => {}
  }
  return onShortcutAction((payload) => {
    if ('dictate' === payload.action || 'photo' === payload.action) {
      navigateToChat()
    }
  })
}
