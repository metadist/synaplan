import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'
import { findApp, isAppAvailable } from '@/apps/catalog'

/**
 * Unknown app ids and apps whose module is off are unknown URLs (U11).
 * Apps with an editor of their own (`to`) forward there.
 */
export async function appRouteGuard(to: RouteLocationNormalized): Promise<true | RouteLocationRaw> {
  const app = findApp(String(to.params.appId ?? ''))
  if (!app || !(await isAppAvailable(app))) return { name: 'not-found' }
  if (app.to) return app.to
  return true
}
