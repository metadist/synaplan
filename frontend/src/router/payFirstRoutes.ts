import type { NavigationGuardNext, RouteLocationNormalized, RouteRecordRaw } from 'vue-router'
import { getConfigSync } from '@/services/api/httpClient'
import { isNativeApp } from '@/services/api/nativeRuntime'

/**
 * The hosted sales flow (pay first): an email address, then payment, and the
 * account exists only after the payment. Its own module so the router's main
 * file does not grow.
 */
/**
 * The native apps sell through their stores, so the Stripe page is absent
 * there, and the page is absent wherever pay first is off.
 */
export function payFirstStartGuard(
  _to: RouteLocationNormalized,
  _from: RouteLocationNormalized,
  next: NavigationGuardNext
): void {
  if (isNativeApp() || getConfigSync().auth?.payFirst !== true) {
    next('/register')
    return
  }
  next()
}

export const payFirstRoutes: RouteRecordRaw[] = [
  {
    path: '/start',
    name: 'start',
    component: () => import('@/views/StartView.vue'),
    beforeEnter: payFirstStartGuard,
    meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.start', i18n: ['auth'] },
  },
  {
    path: '/welcome',
    name: 'welcome',
    component: () => import('@/views/WelcomeView.vue'),
    meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.welcome', i18n: ['auth'] },
  },
]
