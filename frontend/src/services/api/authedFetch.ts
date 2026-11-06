import { getNativeAccessToken } from '@/services/api/nativeAuth'
import { isNativeApp } from '@/services/api/nativeRuntime'
import { refreshAccessToken } from '@/services/api/httpClient'

/**
 * A fetch that carries the same session as the app client: cookies on the
 * web, the stored access token in the native shell, and one refresh after
 * a 401. Plugin pages use this so a cookie-less session can reach their API.
 */
export async function authedFetch(url: string, init: RequestInit = {}): Promise<Response> {
  const send = async (): Promise<Response> => {
    const headers = new Headers(init.headers)
    if (!headers.has('Accept')) {
      headers.set('Accept', 'application/json')
    }
    if (init.body && !(init.body instanceof FormData) && !headers.has('Content-Type')) {
      headers.set('Content-Type', 'application/json')
    }
    if (isNativeApp() && !headers.has('Authorization')) {
      const token = getNativeAccessToken()
      if (token) {
        headers.set('Authorization', `Bearer ${token}`)
      }
    }

    return fetch(url, {
      ...init,
      headers,
      credentials: isNativeApp() ? 'omit' : 'include',
    })
  }

  let response = await send()
  if (response.status === 401) {
    const refreshed = await refreshAccessToken()
    if (refreshed.success && !refreshed.oidcSessionExpired) {
      await new Promise((resolve) => setTimeout(resolve, 100))
      response = await send()
    }
  }

  return response
}
