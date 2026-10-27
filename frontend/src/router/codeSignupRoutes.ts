import type { RouteRecordRaw } from 'vue-router'

/**
 * Invitation code without a password. Public on purpose: the person has no
 * account until the link in the mail is opened.
 */
export const codeSignupRoutes: RouteRecordRaw[] = [
  {
    path: '/start/code',
    name: 'code-signup',
    component: () => import('@/views/CodeSignupView.vue'),
    meta: {
      requiresAuth: false,
      public: true,
      titleKey: 'pageTitles.codeSignup',
      i18n: ['auth'],
    },
  },
]
