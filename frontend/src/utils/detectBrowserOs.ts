/**
 * Best-effort OS of the browser itself, from the standard (non-native)
 * User-Agent string.
 *
 * Deliberately independent of `services/api/nativeRuntime.ts`: that module
 * detects the Capacitor native shell (a security- and store-relevant seam),
 * while this is a plain marketing/UI hint — e.g. leading with the matching
 * store button on the web "get the app" announcement. Never use this for
 * anything that gates a feature: a User-Agent is trivial to spoof.
 */
export function detectBrowserOs(): 'ios' | 'android' | 'other' {
  if ('undefined' === typeof navigator) {
    return 'other'
  }

  const ua = navigator.userAgent
  if (/Android/i.test(ua)) {
    return 'android'
  }
  if (/iPhone|iPad|iPod/i.test(ua)) {
    return 'ios'
  }

  return 'other'
}
