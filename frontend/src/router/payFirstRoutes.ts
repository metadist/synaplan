import type { RouteRecordRaw } from 'vue-router'

/**
 * The hosted sales flow (pay first): an email address, then payment, and the
 * account exists only after the payment. Its own module so the router's main
 * file does not grow.
 */
export const payFirstRoutes: RouteRecordRaw[] = [
  {
    path: '/start',
    name: 'start',
    component: () => import('@/views/StartView.vue'),
    meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.start', i18n: ['auth'] },
  },
  {
    path: '/welcome',
    name: 'welcome',
    component: () => import('@/views/WelcomeView.vue'),
    meta: { requiresAuth: false, public: true, titleKey: 'pageTitles.welcome', i18n: ['auth'] },
  },
]
