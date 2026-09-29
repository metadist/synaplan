import type {
  LocationQuery,
  RouteLocation,
  RouteLocationNormalized,
  RouteLocationRaw,
} from 'vue-router'
import {
  AI_INFRA_PATH,
  LEGACY_AI_TAB,
  resolveConfigDeepLink,
  type OperateSettingsTarget,
} from '@/constants/operateSettings'
import { adminUsersTabRedirect } from './iamGuards'

/**
 * Operate is grouped by topic. These guards keep every bookmark and deep link
 * from before the regrouping working: each one lands on the page and tab that
 * now shows the same thing, with the rest of the query preserved.
 */

const queryString = (value: LocationQuery[string]): string | undefined =>
  typeof value === 'string' && value !== '' ? value : undefined

function toTarget(
  to: RouteLocationNormalized,
  target: OperateSettingsTarget
): RouteLocationRaw | true {
  if (to.path === target.path && to.query.tab === target.tab) return true
  return { path: target.path, query: { ...to.query, tab: target.tab }, hash: to.hash }
}

/** `/admin?tab=…` — Users went to People, Prompts to AI, Moderation to People. */
export function adminDashboardRedirect(to: RouteLocationNormalized): true | RouteLocationRaw {
  const users = adminUsersTabRedirect(to)
  if (users !== true) return users

  const tab = queryString(to.query.tab)
  if (tab === 'prompts') {
    return { path: AI_INFRA_PATH, query: { tab: 'prompts' } }
  }
  if (tab === 'moderation') {
    return { name: 'admin-people', query: { tab: 'moderation' } }
  }
  return true
}

/** `/admin/setup?tab=…` with the tab ids used before the regrouping. */
export function aiInfrastructureRedirect(to: RouteLocationNormalized): true | RouteLocationRaw {
  const tab = queryString(to.query.tab)
  const target = tab ? LEGACY_AI_TAB[tab] : undefined
  return target ? toTarget(to, target) : true
}

/** `/admin/config?tab=…&section=…` written against the backend tab ids. */
export function systemConfigRedirect(to: RouteLocationNormalized): true | RouteLocationRaw {
  const target = resolveConfigDeepLink(queryString(to.query.tab), queryString(to.query.section))
  return target ? toTarget(to, target) : true
}

/** Model status is the Model health tab of AI infrastructure. */
export function modelStatusRedirect(to: RouteLocation): RouteLocationRaw {
  return { path: AI_INFRA_PATH, query: { ...to.query, tab: 'health' }, hash: to.hash }
}
