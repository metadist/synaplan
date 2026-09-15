import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'
import { ref } from 'vue'
import { getMessagesGatewayStatus } from '@/services/api/messagesGatewayApi'
import { isModuleConfigured } from './useModuleFeature'

const anthropicEnabled = ref(false)
let gatewayStatusPromise: Promise<boolean> | null = null
let loadGeneration = 0

export function isHiggsfieldAccountsEnabled(): boolean {
  return isModuleConfigured('higgsfield')
}

/**
 * Anthropic BYO is available only when the Messages gateway reports enabled.
 * That flag is not in runtime config — it comes from GET /messages-gateway.
 * Until the status loads, treat the section as off so the nav does not
 * advertise a surface the instance does not have.
 */
export function isAnthropicAccountsEnabled(): boolean {
  void loadGatewayEnabled()
  return anthropicEnabled.value
}

export function loadGatewayEnabled(): Promise<boolean> {
  if (gatewayStatusPromise) {
    return gatewayStatusPromise
  }
  const generation = loadGeneration
  gatewayStatusPromise = Promise.resolve(getMessagesGatewayStatus())
    .then((status) => {
      const enabled = status?.enabled === true
      if (generation === loadGeneration) {
        anthropicEnabled.value = enabled
      }
      return enabled
    })
    .catch(() => {
      if (generation === loadGeneration) {
        anthropicEnabled.value = false
        gatewayStatusPromise = null
      }
      return false
    })
  return gatewayStatusPromise
}

export function isAiAccountsEnabled(): boolean {
  return isHiggsfieldAccountsEnabled() || isAnthropicAccountsEnabled()
}

/**
 * Both provider sections off: the page is treated as unknown (U11).
 * Kept synchronous so a client-side redirect (legacy /higgsfield) can
 * confirm immediately — an async beforeEnter leaves the old URL in place
 * until GET /messages-gateway returns.
 *
 * `?section=` is the legacy bookmark: allow the hop even when the matching
 * module is not configured; the view hides the empty section.
 */
export function aiAccountsRouteGuard(to?: RouteLocationNormalized): true | RouteLocationRaw {
  const section = to?.query.section
  if (section === 'higgsfield' || section === 'anthropic') {
    return true
  }
  if (isHiggsfieldAccountsEnabled() || isAnthropicAccountsEnabled()) {
    return true
  }
  return { name: 'not-found' }
}

/** Test helper — clears the in-memory gateway status cache. */
export function resetAiAccountsGatewayCache(): void {
  loadGeneration += 1
  anthropicEnabled.value = false
  gatewayStatusPromise = null
}
