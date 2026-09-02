const VITE_DEV_PORTS = new Set(['5173', '4173'])
const API_PORT = '8000'

/**
 * Address the user should type into Synaplan Desktop.
 *
 * In production the web UI and API share one origin. In local Vite the page is
 * on :5173 (or :4173 preview) while the API is published on :8000 — including
 * when the UI is opened via a LAN IP or a custom hostname. Keycloak occupies
 * :8080 and must never be copied into the desktop client.
 */
export function desktopPairingAddress(origin: string = window.location.origin): string {
  try {
    const url = new URL(origin)
    if (VITE_DEV_PORTS.has(url.port)) {
      url.port = API_PORT
      return url.origin
    }
  } catch {
    // Keep the given origin when it is not a valid URL.
  }
  return origin
}
