/**
 * SPA-side seam for the version of the installed app.
 *
 * The native shell (synaplan-apps repo) exposes `window.SynaplanAppInfo` from
 * `app/synaplan-native.js`: the installed binary's version and build, and the
 * Synaplan release the running web bundle was built from. The web bundle is
 * updated over the air, so `web` can be newer than `app`. On the plain web
 * build the bridge is absent and `getNativeAppVersions()` resolves `null`.
 *
 * MOBILE-APP SEAM: default-off. The web build shows no app version.
 */

export interface NativeAppVersions {
  app: string
  build: string
  web: string
}

type BridgeVersions = Partial<Record<keyof NativeAppVersions, unknown>> | null

interface NativeAppInfoApi {
  getVersions: () => Promise<unknown>
}

function getApi(): NativeAppInfoApi | null {
  const api = (globalThis as { SynaplanAppInfo?: unknown }).SynaplanAppInfo
  if (
    api &&
    'object' === typeof api &&
    'function' === typeof (api as NativeAppInfoApi).getVersions
  ) {
    return api as NativeAppInfoApi
  }
  return null
}

const text = (value: unknown): string => ('string' === typeof value ? value.trim() : '')

/** The installed app and web versions, or `null` outside the native app or when unknown. */
export async function getNativeAppVersions(): Promise<NativeAppVersions | null> {
  const api = getApi()
  if (!api) {
    return null
  }
  try {
    const raw = (await api.getVersions()) as BridgeVersions
    const versions = { app: text(raw?.app), build: text(raw?.build), web: text(raw?.web) }
    return versions.app || versions.web ? versions : null
  } catch {
    return null
  }
}
