import type { RouteLocationRaw } from 'vue-router'
import { isDesktopAgentEnabled } from '@/composables/useDesktopAgentFeature'
import { isSavedTasksEnabled } from '@/composables/useSavedTasksFeature'

/** Flag off: the Saved tasks URL is absent, same as the navigation entry. */
export function savedTasksRouteGuard(): true | RouteLocationRaw {
  return isSavedTasksEnabled() ? true : { name: 'not-found' }
}

/** Flag off: the Desktop URL is absent, same as the navigation entry. */
export function desktopRouteGuard(): true | RouteLocationRaw {
  return isDesktopAgentEnabled() ? true : { name: 'not-found' }
}
