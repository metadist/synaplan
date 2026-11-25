import type { RouteRecordRaw } from 'vue-router'

/**
 * The page the link mailed to a new sign-in email opens. Public on purpose:
 * the token in the link is the proof, and the mail may be opened in a browser
 * that is not signed in.
 */
export const emailChangeRoutes: RouteRecordRaw[] = [
  {
    path: '/email-change',
    name: 'email-change',
    component: () => import('@/views/EmailChangeView.vue'),
    meta: {
      requiresAuth: false,
      public: true,
      titleKey: 'pageTitles.emailChange',
      i18n: ['auth'],
    },
  },
]
