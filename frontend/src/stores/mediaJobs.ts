import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useHistoryStore } from '@/stores/history'
import { useChatsStore } from '@/stores/chats'
import { useRealtimeStore } from '@/stores/realtime'
import { useNotification } from '@/composables/useNotification'
import { getConfigSync } from '@/services/api/httpClient'
import { i18n } from '@/i18n'
import { applyMediaJobUpdateToMessage, type MediaJobUpdate } from '@/utils/messageMapper'
import type { RealtimeRuntimeConfig } from '@/services/realtime/types'

/**
 * Background media jobs — the realtime (push-primary) completion path for
 * Release 4.0, Sprint C.
 *
 * Subscribes to the owner's per-user Centrifugo channel (`user:{id}`) and, on a
 * `media_job.update` event, patches the matching loaded message in place (so the
 * banner resolves to the finished media instantly) and raises an actionable
 * completion toast that deep-links to the owning chat. Realtime is best-effort:
 * the banner's own 25s poll remains the fallback when it is disabled/unreachable,
 * and the persisted message state is the durable source of truth on reload.
 */

const TERMINAL_STATES = new Set(['done', 'failed', 'cancelled'])

function isRealtimeEnabled(): boolean {
  const cfg = (getConfigSync() as { realtime?: Partial<RealtimeRuntimeConfig> }).realtime
  return cfg?.enabled ?? false
}

export const useMediaJobsStore = defineStore('mediaJobs', () => {
  let handle: { unsubscribe: () => void } | null = null
  const subscribedUserId = ref<number | null>(null)

  /** Apply one realtime/poll update: patch the loaded message + toast on terminal. */
  function applyUpdate(payload: MediaJobUpdate): void {
    if (payload.message_id != null) {
      const message = useHistoryStore().messages.find(
        (m) => m.backendMessageId === payload.message_id
      )
      if (message) {
        applyMediaJobUpdateToMessage(message, payload)
      }
    }

    if (TERMINAL_STATES.has(payload.state)) {
      raiseTerminalToast(payload)
    }
  }

  function raiseTerminalToast(payload: MediaJobUpdate): void {
    const { push } = useNotification()
    const t = i18n.global.t
    const kind = t(`jobs.kind.${payload.type}`)
    const action =
      payload.chat_id != null
        ? { label: t('jobs.toast.view'), onClick: () => navigateToChat(payload.chat_id as number) }
        : undefined

    if ('done' === payload.state) {
      push({ type: 'success', message: t('jobs.toast.ready', { kind }), duration: 8000, action })
    } else {
      push({ type: 'error', message: t('jobs.toast.failed', { kind }), duration: 8000, action })
    }
  }

  function navigateToChat(chatId: number): void {
    try {
      useChatsStore().setActiveChat(chatId)
    } catch {
      // Best-effort deep link — a toast click must never throw.
    }
  }

  /** Subscribe to the per-user channel (idempotent per user). No-op when realtime is off. */
  async function subscribe(userId: number | null | undefined): Promise<void> {
    if (
      !isRealtimeEnabled() ||
      userId == null ||
      userId <= 0 ||
      subscribedUserId.value === userId
    ) {
      return
    }
    unsubscribe()
    subscribedUserId.value = userId

    try {
      handle = await useRealtimeStore()
        .getOrCreateClient()
        .subscribe(`user:${userId}`, {
          onPublication: (envelope) => {
            if ('media_job.update' === envelope.type) {
              applyUpdate(envelope.data as unknown as MediaJobUpdate)
            }
          },
        })
    } catch {
      subscribedUserId.value = null
    }
  }

  function unsubscribe(): void {
    handle?.unsubscribe()
    handle = null
    subscribedUserId.value = null
  }

  return { applyUpdate, subscribe, unsubscribe, subscribedUserId }
})
