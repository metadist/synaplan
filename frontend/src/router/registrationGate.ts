import type { LocationQuery, RouteLocationRaw } from 'vue-router'

/**
 * `/register` must be unreachable when self-registration is off, not just hidden
 * from the login page. Bookmarks, invitation links and old emails still land
 * here; send them to login and keep any deep-link query so they are not lost.
 */
export function resolveRegistrationRedirect(
  registrationEnabled: boolean,
  query: LocationQuery = {}
): RouteLocationRaw | null {
  if (registrationEnabled) {
    return null
  }

  return { name: 'login', query }
}
