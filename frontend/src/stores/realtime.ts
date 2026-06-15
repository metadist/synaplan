/**
 * Pinia store for the operator-side realtime client.
 *
 * One Centrifuge connection per dashboard tab, multiplexed across many
 * channel subscriptions. The store exposes:
 *
 *   * connection state (badge in the topbar)
 *   * a `getOrCreateClient()` factory used by `widgetOperatorRealtime.ts`
 *   * a `disconnect()` action used on logout
 *
 * The visitor (widget) flow uses RealtimeClient directly — it doesn't
 * load Pinia, so duplicating the lifecycle there is intentional.
 */

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { RealtimeClient } from '@/services/realtime/RealtimeClient'
import type { ConnectionState, RealtimeRuntimeConfig } from '@/services/realtime/types'
import { getConfigSync } from '@/services/api/httpClient'

function readRealtimeConfig(): RealtimeRuntimeConfig {
  const raw = (getConfigSync() as { realtime?: Partial<RealtimeRuntimeConfig> }).realtime
  return {
    enabled: raw?.enabled ?? false,
    wsUrl: raw?.wsUrl ?? '',
  }
}

export const useRealtimeStore = defineStore('realtime', () => {
  // Initialise from the runtime feature flag so the badge / consumers
  // immediately see "disabled" instead of flashing "Offline" before the
  // first subscribe call is made.
  const initialRuntime = readRealtimeConfig()
  const state = ref<ConnectionState>(initialRuntime.enabled ? 'disconnected' : 'disabled')
  const lastError = ref<string | null>(null)
  let client: RealtimeClient | null = null

  function getOrCreateClient(): RealtimeClient {
    if (client) return client
    // Re-read on each create — the runtime config can change between
    // logout and re-login (e.g. an admin toggling the feature flag).
    const runtime = readRealtimeConfig()
    client = new RealtimeClient({
      runtime,
      identity: { kind: 'operator' },
      onStateChange: (next, error) => {
        state.value = next
        lastError.value = error ?? null
      },
    })
    return client
  }

  async function ensureConnected(): Promise<void> {
    const c = getOrCreateClient()
    await c.connect()
  }

  async function disconnect(): Promise<void> {
    if (!client) return
    await client.disconnect()
    client = null
    state.value = 'disconnected'
    lastError.value = null
  }

  return {
    state,
    lastError,
    getOrCreateClient,
    ensureConnected,
    disconnect,
  }
})
