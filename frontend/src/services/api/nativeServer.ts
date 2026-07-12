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
 * from the in-app Settings / "Admin → App server" UI — so the URL parsing,
 * reachability probe and persistence logic all stay app-owned (zero
 * duplication in this submodule). On the plain web build (and any native build
 * without the bootstrap) `window.SynaplanServer` is absent, so every accessor
 * is guarded and `isNativeServerControlAvailable()` returns false.
 *
 * IMPORTANT: `saveNativeServerUrl()`/`resetNativeServerUrl()` only VALIDATE +
 * PERSIST — they do NOT reload the WebView. Callers MUST run their own cleanup
 * (see `NativeServerControl.vue`: sign the user out of every server via
 * `clearAllNativeTokens()`) and then call `reloadNativeApp()` themselves.
 */

import { isNativeApp, isNonProdBuild } from './nativeRuntime'

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
  reload: () => void
}

function getApi(): NativeServerApi | null {
  const api = (globalThis as { SynaplanServer?: unknown }).SynaplanServer
  if (api && 'object' === typeof api && 'function' === typeof (api as NativeServerApi).get) {
    return api as NativeServerApi
  }
  return null
}

/** Defensive URL normalization for comparison (the shell already normalizes). */
function normalizeServerUrl(url: string): string {
  return url.trim().toLowerCase().replace(/\/+$/, '')
}

/**
 * True when purchase/pricing UI may be shown: always on the plain web build;
 * inside the native shell only while the configured server equals the build
 * default (web.synaplan.com in store builds). Apple/Google require in-app
 * purchases to run through the store — a self-hosted server has no IAP
 * catalogue, so the app must not show prices or any purchase path for it.
 *
 * Non-prod builds (dev/staging device builds or the Vite dev server) always
 * allow it, regardless of the configured server, so the purchase flow can be
 * developed and tested locally against any backend.
 */
export function isPurchaseAllowed(): boolean {
  if (!isNativeApp() || isNonProdBuild()) {
    return true
  }
  const defaultUrl = getNativeDefaultServerUrl()
  if ('' === defaultUrl) {
    // Native UA but no SynaplanServer bridge: no way to switch servers, so the
    // app is still on its compiled default.
    return true
  }
  const current = getNativeServerUrl() || defaultUrl
  return normalizeServerUrl(current) === normalizeServerUrl(defaultUrl)
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
 * Probe + persist a new backend URL. Does NOT reload the WebView — on a
 * resolved `ok` result, the caller must run its own cleanup and then call
 * `reloadNativeApp()` to actually switch over to the new server.
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

/**
 * Reset to the build/compiled default server. Does NOT reload the WebView —
 * see `saveNativeServerUrl()`; the caller must call `reloadNativeApp()` after
 * its own cleanup.
 */
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

/**
 * Reload the WebView so the SPA re-bootstraps against the server persisted by
 * `saveNativeServerUrl()`/`resetNativeServerUrl()`. Call this only after any
 * SPA-side cleanup (e.g. signing the user out) has finished.
 */
export function reloadNativeApp(): void {
  const api = getApi()
  if (!api) {
    return
  }
  try {
    api.reload()
  } catch {
    /* no-op: nothing the SPA can do if the native bridge is gone */
  }
}
