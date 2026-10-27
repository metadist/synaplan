import type { RouteRecordRaw } from 'vue-router'

/**
 * The page the emailed sign-in link opens. Public on purpose: the person
 * is not signed in until the token in the link is accepted.
 */
export const loginLinkRoutes: RouteRecordRaw[] = [
  {
    path: '/login/link',
    name: 'login-link',
    component: () => import('@/views/LoginLinkView.vue'),
    meta: {
      requiresAuth: false,
      public: true,
      titleKey: 'pageTitles.loginLink',
      i18n: ['auth'],
    },
  },
]
