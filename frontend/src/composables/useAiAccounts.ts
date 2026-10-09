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

/**
 * Store a gateway status this screen already loaded.
 * Availability checks then read that result instead of sending a second
 * GET /messages-gateway that could fail and hide a page the first response
 * already proved is available.
 */
export function rememberGatewayEnabled(enabled: boolean): void {
  loadGeneration += 1
  anthropicEnabled.value = enabled
  gatewayStatusPromise = Promise.resolve(enabled)
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

/** Test helper — clears the in-memory gateway status cache. */
export function resetAiAccountsGatewayCache(): void {
  loadGeneration += 1
  anthropicEnabled.value = false
  gatewayStatusPromise = null
}
