import type { RouteRecordRaw } from 'vue-router'
import { peopleRouteGuard } from '@/router/iamGuards'

/**
 * The two admin user lists: People (accounts, groups, codes) and Usage
 * (the cost board). They sit next to each other in Operate, so their route
 * records live together here instead of growing the router's main file.
 */
export const peopleRoute: RouteRecordRaw = {
  path: '/admin/people',
  name: 'admin-people',
  component: () => import('@/views/PeopleView.vue'),
  meta: {
    requiresAuth: true,
    requiresAdmin: true,
    titleKey: 'pageTitles.adminPeople',
    i18n: ['admin', 'config'],
  },
  beforeEnter: peopleRouteGuard,
}

export const adminUsageRoute: RouteRecordRaw = {
  path: '/admin/usage',
  name: 'admin-usage',
  component: () => import('@/views/AdminUsageView.vue'),
  meta: {
    requiresAuth: true,
    requiresAdmin: true,
    titleKey: 'pageTitles.adminUsage',
    i18n: ['admin', 'config'],
  },
}
