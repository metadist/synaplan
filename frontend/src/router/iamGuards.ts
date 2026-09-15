import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'
import { isIamGroupsEnabled, isIamPoliciesEnabled } from '@/composables/useIamFeature'
import { isPlatformLinksEnabled } from '@/composables/usePlatformLinksFeature'

/**
 * People stays reachable when any extra tab can render (groups, policies, or
 * platform instances). Only when every extra tab is off does the route land
 * on the Operate user list instead of a dead end.
 */
export function peopleRouteGuard(): true | RouteLocationRaw {
  if (isIamGroupsEnabled() || isIamPoliciesEnabled() || isPlatformLinksEnabled()) {
    return true
  }
  return { name: 'admin', query: { tab: 'users' } }
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
 * Legacy `/admin?tab=users` only becomes a People landing when IAM groups
 * are on. Otherwise AdminView keeps its own Users table.
 */
export function adminUsersTabRedirect(to: RouteLocationNormalized): true | RouteLocationRaw {
  if (to.query.tab === 'users' && isIamGroupsEnabled()) {
    return { name: 'admin-people' }
  }
  return true
}
