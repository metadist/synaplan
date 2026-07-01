/**
 * SPA-side seam for the app-owned native server control surface.
 *
 * The native shell (synaplan-apps repo) injects `app/synaplan-native.js` BEFORE
 * the SPA bundle. That script owns the single source of truth for "which backend
 * does this app talk to" (a client-side bootstrap value persisted in
 * localStorage, read synchronously into `window.__SYNAPLAN_API_BASE_URL__` before
 * the SPA boots). It also exposes a tiny imperative API on `window.SynaplanServer`.
 *
 * This module is the typed wrapper the SPA uses to read and change that value
 * from the in-app "Admin → App server" panel — so the URL parsing, reachability
 * probe, persistence and reload logic all stay app-owned (zero duplication in
 * this submodule). On the plain web build (and any native build without the
 * bootstrap) `window.SynaplanServer` is absent, so every accessor is guarded and
 * `isNativeServerControlAvailable()` returns false.
 */

/** Result of a save attempt: `ok` only when the target server was reachable. */
export interface NativeServerSaveResult {
  ok: boolean
  error?: string
}

interface NativeServerApi {
  get: () => string
  getDefault: () => string
  open: () => void
  save: (url: string) => Promise<NativeServerSaveResult>
  reset: () => void
}

function getApi(): NativeServerApi | null {
  const api = (globalThis as { SynaplanServer?: unknown }).SynaplanServer
  if (api && 'object' === typeof api && 'function' === typeof (api as NativeServerApi).get) {
    return api as NativeServerApi
  }
  return null
}

/** True when the app-owned native server control surface is present. */
export function isNativeServerControlAvailable(): boolean {
  return null !== getApi()
}

/** Currently configured backend base URL (no trailing slash), or '' if unknown. */
export function getNativeServerUrl(): string {
  const api = getApi()
  try {
    return api ? api.get() : ''
  } catch {
    return ''
  }
}

/** The compiled/build default backend base URL, or '' if unknown. */
export function getNativeDefaultServerUrl(): string {
  const api = getApi()
  try {
    return api ? api.getDefault() : ''
  } catch {
    return ''
  }
}

/**
 * Probe + persist a new backend URL. On success the native shell reloads the
 * WebView so the SPA re-bootstraps against the new server, so a resolved `ok`
 * result is typically the last thing the caller observes before the reload.
 */
export async function saveNativeServerUrl(url: string): Promise<NativeServerSaveResult> {
  const api = getApi()
  if (!api) {
    return { ok: false, error: 'unavailable' }
  }
  try {
    return await api.save(url)
  } catch {
    return { ok: false, error: 'unavailable' }
  }
}

/**
 * Open the app-owned native server overlay (dismissable), so the user can point
 * the app at a different Synaplan server. Available on every screen regardless of
 * auth state — this is the "change the server even when logged out" entry point.
 * No-op when the native control surface is absent (plain web build).
 */
export function openNativeServerOverlay(): void {
  const api = getApi()
  if (!api) {
    return
  }
  try {
    api.open()
  } catch {
    /* no-op: nothing the SPA can do if the native bridge is gone */
  }
}

/** Reset to the build/compiled default server and reload the WebView. */
export function resetNativeServerUrl(): void {
  const api = getApi()
  if (!api) {
    return
  }
  try {
    api.reset()
  } catch {
    /* no-op: nothing the SPA can do if the native bridge is gone */
  }
}
