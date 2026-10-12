import type { RouteLocation, RouteLocationRaw } from 'vue-router'
import { AI_INFRA_PATH } from '@/constants/operateSettings'
import { useAuthStore } from '@/stores/auth'

/** Model tabs that moved from AI settings to Admin › AI › Model catalog. */
const ADMIN_MODEL_TABS = new Set(['runs', 'edit'])

/** Topics (custom task prompts) are a tab of AI settings, whatever the Assistants flag says. */
export function topicsRedirect(to: Pick<RouteLocation, 'query'>): RouteLocationRaw {
  return { path: '/ai/models', query: { ...to.query, tab: 'topics' } }
}

/** Routing is an instance setting: admins land on Admin › AI › Chat behaviour. */
export function routingRedirect(): RouteLocationRaw {
  return useAuthStore().isAdmin
    ? { path: AI_INFRA_PATH, query: { tab: 'behavior' } }
    : { path: '/ai/models' }
}

/** `/ai/models?tab=runs|edit` bookmarks follow the catalog to Admin. */
export function aiModelsTabRedirect(to: Pick<RouteLocation, 'query'>): true | RouteLocationRaw {
  const tab = to.query.tab
  if (typeof tab !== 'string' || !ADMIN_MODEL_TABS.has(tab)) return true
  if (useAuthStore().isAdmin) return { path: AI_INFRA_PATH, query: { tab: 'catalog' } }
  const query = { ...to.query }
  delete query.tab
  return { path: '/ai/models', query }
}
