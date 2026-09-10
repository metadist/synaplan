import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'
import { isIamGroupsEnabled } from '@/composables/useIamFeature'

/**
 * Flag off: /admin/people is treated as unknown (U5 / U11).
 * Nav already hides the People child; this stops the URL and the
 * `/admin?tab=users` redirect from offering a surface whose APIs 404.
 */
export function peopleRouteGuard(to?: RouteLocationNormalized): true | RouteLocationRaw {
  if (isIamGroupsEnabled()) {
    return true
  }
  const path = to?.path.replace(/^\//, '') ?? 'admin/people'
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
