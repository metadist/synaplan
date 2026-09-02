/**
 * Address the user should type into Synaplan Desktop.
 *
 * In production the web UI and API share one origin. In local Vite the page is
 * on :5173 (or :4173 preview) while the API is published on :8000. Keycloak
 * occupies :8080 and must never be copied into the desktop client.
 */
export function desktopPairingAddress(origin: string = window.location.origin): string {
  try {
    const url = new URL(origin)
    const local = url.hostname === 'localhost' || url.hostname === '127.0.0.1'
    if (local && (url.port === '5173' || url.port === '4173')) {
      return `${url.protocol}//${url.hostname}:8000`
    }
  } catch {
    // Keep the given origin when it is not a valid URL.
  }
  return origin
}
