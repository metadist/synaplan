import type { RouteRecordRaw } from 'vue-router'
import { isModuleExplicitlyConfigured } from '@/composables/useModuleFeature'

/**
 * Admin › System › Capacity (architecture update AU7). The page exists only
 * while the watchdog reports: with `SYNAPLAN_WATCHDOG=0` the nav hides the
 * entry and this route is the not-found page (UX contract U11), so a
 * bookmark or a typed URL is never a dead control.
 */
export const capacityRoute: RouteRecordRaw = {
  path: '/admin/capacity',
  name: 'admin-capacity',
  component: () => import('@/views/CapacityView.vue'),
  meta: {
    requiresAuth: true,
    requiresAdmin: true,
    titleKey: 'pageTitles.adminCapacity',
    i18n: ['admin'],
  },
  beforeEnter: (_to, _from, next) => {
    if (isModuleExplicitlyConfigured('capacity')) {
      next()
      return
    }
    next({ name: 'not-found', params: { pathMatch: ['admin', 'capacity'] }, replace: true })
  },
}
