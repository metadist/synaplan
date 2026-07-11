import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useHistoryStore } from '@/stores/history'
import { useChatsStore } from '@/stores/chats'
import { useRealtimeStore } from '@/stores/realtime'
import { useUsageTaximeterStore } from '@/stores/usageTaximeter'
import { useNotification } from '@/composables/useNotification'
import { getConfigSync } from '@/services/api/httpClient'
import { i18n } from '@/i18n'
import { applyMediaJobUpdateToMessage, type MediaJobUpdate } from '@/utils/messageMapper'
import {
  fetchActiveMediaJobs,
  cancelMediaJob,
  type MediaJobTrayItem,
} from '@/services/api/mediaJobApi'
import type { RealtimeRuntimeConfig } from '@/services/realtime/types'

/** One in-flight job as shown in the global Jobs tray. */
export interface TrayJob {
  jobId: string
  type: string
  state: string
  percent?: number
  chatId?: number | null
  messageId?: number | null
  prompt?: string
}

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

  /** In-flight jobs across all chats — the global Jobs tray's source of truth. */
  const activeJobs = ref<TrayJob[]>([])
  const activeCount = computed(() => activeJobs.value.length)

  /** Apply one realtime/poll update: patch the loaded message + maintain the tray + toast on terminal. */
  function applyUpdate(payload: MediaJobUpdate): void {
    const loadedMessage =
      payload.message_id != null
        ? useHistoryStore().messages.find((m) => m.backendMessageId === payload.message_id)
        : undefined
    if (loadedMessage) {
      applyMediaJobUpdateToMessage(loadedMessage, payload)
    }

    if (TERMINAL_STATES.has(payload.state)) {
      removeActive(payload.job_id)
      raiseTerminalToast(payload)

      // Usage taximeter: the render is billed by the worker around completion,
      // and the job can become visible as "done" moments BEFORE the billing row
      // and usage meta are committed. refreshAfterSettlement pulls day totals
      // now and retries briefly, re-reconciling the message so the persisted
      // usage meta lands in the session model list without a page reload.
      const taximeter = useUsageTaximeterStore()
      if ('done' === payload.state && taximeter.active) {
        const reconcile =
          loadedMessage && payload.message_id != null
            ? () => {
                const historyStore = useHistoryStore()
                void historyStore
                  .reconcileMessage(loadedMessage.id, payload.message_id as number)
                  .then(() => taximeter.seedFromHistory(historyStore.messages))
              }
            : undefined
        taximeter.refreshAfterSettlement(reconcile)
      }
    } else {
      upsertActive(payload)
    }
  }

  /** Fetch the user's active jobs for the tray (best-effort). */
  async function loadActive(): Promise<void> {
    try {
      activeJobs.value = (await fetchActiveMediaJobs()).map(toTrayJob)
    } catch {
      // Best-effort: the tray simply shows nothing rather than erroring.
    }
  }

  /** Cancel a job, optimistically dropping it from the tray. */
  async function cancel(jobId: string): Promise<void> {
    try {
      await cancelMediaJob(jobId)
      removeActive(jobId)
    } catch {
      useNotification().error(i18n.global.t('jobs.cancel.failed'))
    }
  }

  function toTrayJob(item: MediaJobTrayItem): TrayJob {
    return {
      jobId: item.job_id,
      type: item.type,
      state: item.state,
      percent: item.percent ?? undefined,
      chatId: item.chat_id ?? null,
      messageId: item.message_id ?? null,
      prompt: item.prompt,
    }
  }

  function upsertActive(payload: MediaJobUpdate): void {
    const next: TrayJob = {
      jobId: payload.job_id,
      type: payload.type,
      state: payload.state,
      percent: payload.percent ?? undefined,
      chatId: payload.chat_id ?? null,
      messageId: payload.message_id ?? null,
    }
    const existing = activeJobs.value.find((j) => j.jobId === payload.job_id)
    if (existing) {
      Object.assign(existing, next)
    } else {
      activeJobs.value.push(next)
    }
  }

  function removeActive(jobId: string): void {
    activeJobs.value = activeJobs.value.filter((j) => j.jobId !== jobId)
  }

  function raiseTerminalToast(payload: MediaJobUpdate): void {
    const { push } = useNotification()
    const t = i18n.global.t
    const kind = t(`jobs.kind.${payload.type}`)
    const action =
      payload.chat_id != null
        ? { label: t('jobs.toast.view'), onClick: () => openChat(payload.chat_id as number) }
        : undefined

    if ('done' === payload.state) {
      push({ type: 'success', message: t('jobs.toast.ready', { kind }), duration: 8000, action })
    } else {
      push({ type: 'error', message: t('jobs.toast.failed', { kind }), duration: 8000, action })
    }
  }

  /** Deep-link to a chat (toast "View" + tray "Open"). Best-effort, never throws. */
  function openChat(chatId: number): void {
    try {
      useChatsStore().setActiveChat(chatId)
    } catch {
      // Best-effort deep link — a click must never throw.
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

  return {
    activeJobs,
    activeCount,
    applyUpdate,
    loadActive,
    cancel,
    openChat,
    subscribe,
    unsubscribe,
    subscribedUserId,
  }
})
