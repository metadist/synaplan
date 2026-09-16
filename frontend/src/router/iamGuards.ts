import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'
import { isIamGroupsEnabled } from '@/composables/useIamFeature'

/**
 * People is always the user list (NV01). Groups / Policies / Platform
 * instances / Audit stay flag-gated on the page itself; the route is never
 * a dead end — including when only platform links are on (NV03).
 */
export function peopleRouteGuard(): true | RouteLocationRaw {
  return true
}

/**
 * Flag off: /groups has no data, its APIs 404 (U5 / U11) and its only action
 * links into People, so the route is treated as unknown. Nav already hides the
 * entry; this covers a bookmark or a hand-typed URL.
 */
export function groupsRouteGuard(to?: RouteLocationNormalized): true | RouteLocationRaw {
  if (isIamGroupsEnabled()) {
    return true
  }
  const path = to?.path.replace(/^\//, '') ?? 'groups'
  return {
    name: 'not-found',
    params: { pathMatch: path.split('/') },
    query: to?.query,
    hash: to?.hash,
  }
}

/**
 * Legacy `/admin?tab=users` always lands on People — the Operate Users tab
 * is gone (NV01).
 */
export function adminUsersTabRedirect(to: RouteLocationNormalized): true | RouteLocationRaw {
  if (to.query.tab === 'users') {
    return { name: 'admin-people' }
  }
  return true
}
