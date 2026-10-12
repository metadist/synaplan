/**
 * The start response builds the socket from the API host. Behind the dev
 * proxy that host is the internal backend name. The browser connects on the
 * page origin; Caddy and the dev server both forward /voice/ws.
 */
export function liveVoiceSocketUrl(
  wsUrl: string,
  page: { protocol: string; host: string }
): string {
  const parsed = new URL(wsUrl, `${page.protocol}//${page.host}`)
  const scheme = page.protocol === 'https:' ? 'wss:' : 'ws:'
  return `${scheme}//${page.host}${parsed.pathname}${parsed.search}`
}
