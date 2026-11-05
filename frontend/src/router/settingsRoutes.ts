import type { RouteRecordRaw } from 'vue-router'
import { BUNDLE_PANEL_I18N_NAMESPACES } from '@/i18n'
import { matchesPhoneChrome } from '@/composables/usePhoneChrome'
import {
  normalizeSettingsRoute,
  settingsLocationFor,
  shouldShowSettingsHub,
} from '@/composables/useSettingsSections'

/**
 * Phone chrome keeps a bare /settings as the section list. Desktop, guests,
 * and old hashes still pick a section. Each signed-in section is its own page.
 */
export const settingsRoutes: RouteRecordRaw[] = [
  {
    path: '/settings',
    name: 'settings',
    component: () => import('@/views/SettingsView.vue'),
    beforeEnter: (to) =>
      shouldShowSettingsHub(to, matchesPhoneChrome()) ? true : settingsLocationFor(to),
    meta: {
      requiresAuth: false,
      titleKey: 'pageTitles.settings',
      i18n: ['settings', 'config', 'tools', ...BUNDLE_PANEL_I18N_NAMESPACES],
    },
  },
  {
    path: '/settings/:section',
    name: 'settings-section',
    component: () => import('@/views/SettingsView.vue'),
    beforeEnter: (to) => normalizeSettingsRoute(to),
    meta: {
      requiresAuth: false,
      titleKey: 'pageTitles.settings',
      i18n: ['settings', 'config', 'tools', ...BUNDLE_PANEL_I18N_NAMESPACES],
    },
  },
]
