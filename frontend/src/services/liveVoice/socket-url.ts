/**
 * The start response builds the socket from the API host. Behind the dev
 * proxy that host is the internal backend name, and the page origin forwards
 * /voice/ws. A native app has no such proxy: its page origin is not the API,
 * so an absolute API base wins.
 */
export function liveVoiceSocketUrl(
  wsUrl: string,
  page: { protocol: string; host: string },
  apiBaseUrl = ''
): string {
  const parsed = new URL(wsUrl, `${page.protocol}//${page.host}`)
  const api = httpOrigin(apiBaseUrl)
  const scheme = (api?.protocol ?? page.protocol) === 'https:' ? 'wss:' : 'ws:'
  const host = api?.host ?? page.host
  return `${scheme}//${host}${parsed.pathname}${parsed.search}`
}

function httpOrigin(apiBaseUrl: string): URL | null {
  if (!/^https?:\/\//i.test(apiBaseUrl)) return null
  try {
    return new URL(apiBaseUrl)
  } catch {
    return null
  }
}
