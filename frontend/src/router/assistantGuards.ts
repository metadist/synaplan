import type { RouteLocationRaw } from 'vue-router'
import { isAgentsEnabled } from '@/composables/useAgentsFeature'

/** Flag off: /ai/assistants is treated as unknown. */
export function assistantsRouteGuard(): true | RouteLocationRaw {
  return isAgentsEnabled() ? true : { name: 'not-found' }
}
