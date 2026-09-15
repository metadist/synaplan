import type { RouteLocationRaw } from 'vue-router'
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
 * Until the status loads, treat the section as off so the nav and route do
 * not advertise a surface the instance does not have.
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

/** Both provider sections off: the page is treated as unknown (U11). */
export async function aiAccountsRouteGuard(): Promise<true | RouteLocationRaw> {
  if (isHiggsfieldAccountsEnabled()) {
    return true
  }
  return (await loadGatewayEnabled()) ? true : { name: 'not-found' }
}

/** Test helper — clears the in-memory gateway status cache. */
export function resetAiAccountsGatewayCache(): void {
  loadGeneration += 1
  anthropicEnabled.value = false
  gatewayStatusPromise = null
}
