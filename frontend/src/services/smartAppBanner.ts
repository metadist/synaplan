/**
 * MOBILE-APP SEAM: Safari's Smart App Banner, offered to visitors of a server
 * that publishes an iOS app.
 *
 * The banner is opt-in and driven by the operator's `IOS_APP_URL` setting, which
 * is empty by default. A self-hosted instance therefore keeps its current
 * behaviour and never advertises somebody else's App Store listing.
 *
 * Safari only honours the tag on a real web page; it is inert inside WKWebView,
 * but the native shell is excluded anyway so the bundled web layer cannot
 * advertise the app the user is already running.
 */
import { isNativeApp } from './api/nativeRuntime'

const META_NAME = 'apple-itunes-app'

/**
 * Extracts the numeric App Store id from a product URL. Safari's banner needs
 * the bare id, while the operator configures a full link that is also used by
 * the forced-update screen.
 */
export function appleAppIdFromStoreUrl(storeUrl: string): string {
  return /\/id(\d+)/.exec(storeUrl)?.[1] ?? ''
}

/**
 * Adds the banner meta tag. Returns whether a tag was installed, which keeps the
 * behaviour observable in tests without reaching into the DOM from the caller.
 */
export function installSmartAppBanner(iosAppUrl: string): boolean {
  if (isNativeApp()) {
    return false
  }

  const appId = appleAppIdFromStoreUrl(iosAppUrl)

  if ('' === appId) {
    return false
  }

  if (document.querySelector(`meta[name="${META_NAME}"]`)) {
    return false
  }

  const meta = document.createElement('meta')
  meta.name = META_NAME
  meta.content = `app-id=${appId}`
  document.head.appendChild(meta)

  return true
}
