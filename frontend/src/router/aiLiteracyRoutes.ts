import type { RouteRecordRaw } from 'vue-router'

export const aiLiteracyRoutes: RouteRecordRaw[] = [
  {
    path: '/help/using-ai-well',
    name: 'using-ai-well',
    component: () => import('@/views/UsingAiWellView.vue'),
    meta: { requiresAuth: true, titleKey: 'pageTitles.usingAiWell', i18n: ['admin'] },
  },
]
