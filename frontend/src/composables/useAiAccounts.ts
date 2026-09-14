import type { RouteLocationRaw } from 'vue-router'
import { getConfigSync } from '@/services/api/httpClient'
import { isModuleConfigured } from './useModuleFeature'

export function isHiggsfieldAccountsEnabled(): boolean {
  return isModuleConfigured('higgsfield')
}

export function isAnthropicAccountsEnabled(): boolean {
  const features = getConfigSync().features as Record<string, boolean | undefined> | undefined
  return features?.messagesGateway !== false
}

export function isAiAccountsEnabled(): boolean {
  return isHiggsfieldAccountsEnabled() || isAnthropicAccountsEnabled()
}

/** Both provider sections off: the page is treated as unknown (U11). */
export function aiAccountsRouteGuard(): true | RouteLocationRaw {
  return isAiAccountsEnabled() ? true : { name: 'not-found' }
}
