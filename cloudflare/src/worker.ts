interface Env {}

const NO_CACHE_PATHS = ['/sw.js', '/site.webmanifest']

/**
 * SSE endpoints. The Worker MUST pass these through with zero header
 * mutation so Cloudflare doesn't accidentally buffer chunked responses.
 *
 * `await fetch(request)` returns a streaming Response whose `body` is a
 * ReadableStream — re-emitting `response.body` (rather than awaiting
 * `response.text()`) is what keeps the byte stream live to the browser.
 *
 * Phase 1f: short-circuit for SSE paths so future header tweaks below
 * (Cache-Control, etc.) cannot re-introduce a buffering regression.
 */
const SSE_PATH_PREFIXES = [
  '/api/v1/messages/stream',
  '/api/v1/notifications/stream',
] as const
const SSE_PATH_PATTERNS = [
  /^\/api\/v1\/widget\/[^/]+\/message$/,
  /^\/api\/v1\/widgets\/[^/]+\/sessions\/[^/]+\/events$/,
] as const

function isSseRequest(pathname: string, request: Request): boolean {
  const accept = request.headers.get('Accept') || ''
  if (accept.includes('text/event-stream')) {
    return true
  }
  if (SSE_PATH_PREFIXES.some((p) => pathname === p || pathname.startsWith(`${p}?`))) {
    return true
  }
  return SSE_PATH_PATTERNS.some((re) => re.test(pathname))
}

function shouldNeverCache(pathname: string): boolean {
  return NO_CACHE_PATHS.includes(pathname)
}

function isHashedAsset(pathname: string): boolean {
  return pathname.startsWith('/assets/')
}

function isHtmlNavigation(request: Request, pathname: string): boolean {
  const accept = request.headers.get('Accept') || ''
  return (
    accept.includes('text/html') &&
    !pathname.startsWith('/api/') &&
    !pathname.startsWith('/shared/') &&
    !pathname.startsWith('/uploads/')
  )
}

export default {
  async fetch(request: Request, _env: Env): Promise<Response> {
    const url = new URL(request.url)
    const { pathname } = url

    // SSE bypass — return the streaming Response unmodified. Don't even
    // wrap it in a new Response(); cloning the body to copy headers can
    // sometimes cause the runtime to buffer the entire body before
    // emitting the first byte (depends on Worker bundle / runtime
    // version). The origin (Caddy + FrankenPHP) already sets the right
    // SSE headers (`Cache-Control: no-cache`, `X-Accel-Buffering: no`).
    if (isSseRequest(pathname, request)) {
      return fetch(request)
    }

    const response = await fetch(request)
    const headers = new Headers(response.headers)

    if (shouldNeverCache(pathname)) {
      headers.set('Cache-Control', 'no-cache, no-store, must-revalidate')
      headers.set('Pragma', 'no-cache')
      headers.set('Expires', '0')
    } else if (isHashedAsset(pathname)) {
      headers.set('Cache-Control', 'public, max-age=31536000, immutable')
    } else if (isHtmlNavigation(request, pathname)) {
      headers.set('Cache-Control', 'no-cache')
    }

    return new Response(response.body, {
      status: response.status,
      statusText: response.statusText,
      headers,
    })
  },
} satisfies ExportedHandler<Env>
