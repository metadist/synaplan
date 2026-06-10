/**
 * Vue composable for subscribing to a Centrifugo channel from a component.
 *
 * Usage:
 *
 *   const { state, lastEvent } = useRealtimeChannel<MessageEvent>(
 *     `widget:operators.${widgetId}`,
 *     {
 *       onPublication: (event) => { ... }
 *     }
 *   )
 *
 * Lifecycle:
 *   * subscribes on mount
 *   * unsubscribes on unmount
 *   * resubscribes if the channel name (a `Ref`) changes
 *
 * Operator-side. For the embedded widget (which doesn't load Pinia) call
 * `RealtimeClient` directly.
 */

import { onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue'
import { useRealtimeStore } from '@/stores/realtime'
import type { ChannelHandle } from '@/services/realtime/RealtimeClient'
import type { RealtimeEnvelope, SubscribeOptions } from '@/services/realtime/types'

export interface UseRealtimeChannelOptions<
  TPayload extends Record<string, unknown>,
> extends SubscribeOptions<TPayload> {
  /**
   * Skip the auto-subscribe on mount. Useful when the channel name isn't
   * known yet (e.g. waiting on an async session creation).
   */
  immediate?: boolean
}

export function useRealtimeChannel<
  TPayload extends Record<string, unknown> = Record<string, unknown>,
>(channelName: string | Ref<string | null>, options: UseRealtimeChannelOptions<TPayload>) {
  const store = useRealtimeStore()
  const handle = ref<ChannelHandle | null>(null)
  const lastEvent = ref<RealtimeEnvelope<TPayload> | null>(null)
  const error = ref<string | null>(null)

  const channelRef: Ref<string | null> =
    typeof channelName === 'string' ? (ref(channelName) as Ref<string | null>) : channelName

  async function subscribe(): Promise<void> {
    const ch = channelRef.value
    if (!ch) return
    await teardown()
    error.value = null
    try {
      const client = store.getOrCreateClient()
      handle.value = await client.subscribe<TPayload>(ch, {
        onPublication: (event) => {
          lastEvent.value = event
          options.onPublication(event)
        },
        onJoin: options.onJoin,
        onLeave: options.onLeave,
        onError: (msg) => {
          error.value = msg
          options.onError?.(msg)
        },
      })
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
      options.onError?.(error.value)
    }
  }

  async function teardown(): Promise<void> {
    if (!handle.value) return
    handle.value.unsubscribe()
    handle.value = null
  }

  onMounted(() => {
    if (options.immediate === false) return
    void subscribe()
  })

  watch(channelRef, () => {
    if (options.immediate === false) return
    void subscribe()
  })

  onBeforeUnmount(() => {
    void teardown()
  })

  return {
    state: store.state,
    lastError: store.lastError,
    lastEvent,
    error,
    subscribe,
    unsubscribe: teardown,
  }
}
