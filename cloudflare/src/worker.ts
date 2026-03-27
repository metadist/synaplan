interface Env {}

const NO_CACHE_PATHS = ['/sw.js', '/site.webmanifest']

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
