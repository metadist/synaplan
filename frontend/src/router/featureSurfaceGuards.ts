import type { RouteLocation, RouteLocationRaw } from 'vue-router'
import { isApprovalsEnabled } from '@/composables/useApprovalsFeature'
import { isSavedTasksEnabled } from '@/composables/useSavedTasksFeature'

/** Flag off: the Tasks URL is absent, same as the navigation entry. */
export function savedTasksRouteGuard(): true | RouteLocationRaw {
  return isSavedTasksEnabled() ? true : { name: 'not-found' }
}

/** Flag off: the Approvals URL is absent, same as the navigation entry. */
export function approvalsRouteGuard(): true | RouteLocationRaw {
  return isApprovalsEnabled() ? true : { name: 'not-found' }
}

/**
 * `/channels/connections` was the shared connections page. OAuth callbacks
 * still return there with `?m365=` / `?dropbox=`, so the result lands on the
 * matching app page where the panel reads it.
 */
export function connectionsRedirect(to: Pick<RouteLocation, 'query'>): RouteLocationRaw {
  if (typeof to.query.m365 === 'string') return { path: '/apps/microsoft365', query: to.query }
  if (typeof to.query.dropbox === 'string') return { path: '/apps/dropbox', query: to.query }
  return { path: '/apps/connected' }
}
