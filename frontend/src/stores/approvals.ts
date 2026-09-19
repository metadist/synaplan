import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { approvalsApi, type Approval } from '@/services/api/approvalsApi'
import { isApprovalsEnabled } from '@/composables/useApprovalsFeature'
import { useRealtimeStore } from '@/stores/realtime'
import { getConfigSync } from '@/services/api/httpClient'
import type { RealtimeRuntimeConfig } from '@/services/realtime/types'

function isRealtimeEnabled(): boolean {
  const cfg = (getConfigSync() as { realtime?: Partial<RealtimeRuntimeConfig> }).realtime
  return cfg?.enabled ?? false
}

/** Published by the backend after a chat approval appends its thread follow-up (Q1). */
export const APPROVAL_CHAT_CONTINUED_EVENT = 'approval.chat_continued'

export interface ChatContinuation {
  approvalId: number
  chatId: number
  outcome: string
}

/** Non-realtime fallback cadence + bound for waitForOutcome. */
export const APPROVAL_OUTCOME_POLL_INTERVAL_MS = 1500
export const APPROVAL_OUTCOME_TIMEOUT_MS = 30_000

const TERMINAL_APPROVAL_STATUSES = new Set(['executed', 'failed', 'rejected'])

export function parseChatContinuation(data: unknown): ChatContinuation | null {
  if (typeof data !== 'object' || data === null) {
    return null
  }
  const { approvalId, chatId, outcome } = data as Record<string, unknown>
  if (typeof approvalId !== 'number' || typeof chatId !== 'number' || typeof outcome !== 'string') {
    return null
  }
  return { approvalId, chatId, outcome }
}

export const useApprovalsStore = defineStore('approvals', () => {
  const pending = ref<Approval[]>([])
  const decided = ref<Approval[]>([])
  const pendingCount = ref(0)
  const loading = ref(false)
  let handle: { unsubscribe: () => void } | null = null
  const subscribedUserId = ref<number | null>(null)
  const continuationListeners = new Set<(continuation: ChatContinuation) => void>()

  const hasPending = computed(() => pendingCount.value > 0)

  async function load(status: 'pending' | 'decided' = 'pending'): Promise<void> {
    if (!isApprovalsEnabled()) {
      pending.value = []
      decided.value = []
      pendingCount.value = 0
      return
    }
    loading.value = true
    try {
      const data = await approvalsApi.list(status)
      pendingCount.value = data.pendingCount
      if (status === 'pending') {
        pending.value = data.approvals
      } else {
        decided.value = data.approvals
      }
    } catch {
      if (status === 'pending') {
        pending.value = []
        pendingCount.value = 0
      }
    } finally {
      loading.value = false
    }
  }

  function addFromStream(approval: Approval): void {
    if (pending.value.some((row) => row.id === approval.id)) {
      return
    }
    pending.value = [approval, ...pending.value]
    pendingCount.value += 1
  }

  async function approve(id: number, alwaysAllow = false, assistantKey?: string): Promise<void> {
    await approvalsApi.approve(id, alwaysAllow, assistantKey)
    pending.value = pending.value.filter((row) => row.id !== id)
    pendingCount.value = Math.max(0, pendingCount.value - 1)
  }

  async function reject(id: number, reason?: string): Promise<void> {
    await approvalsApi.reject(id, reason)
    pending.value = pending.value.filter((row) => row.id !== id)
    pendingCount.value = Math.max(0, pendingCount.value - 1)
  }

  function onChatContinued(listener: (continuation: ChatContinuation) => void): () => void {
    continuationListeners.add(listener)
    return () => {
      continuationListeners.delete(listener)
    }
  }

  function ingestContinuationEvent(data: unknown): void {
    const continuation = parseChatContinuation(data)
    if (!continuation) {
      return
    }
    continuationListeners.forEach((listener) => listener(continuation))
  }

  /**
   * Non-realtime fallback: poll the decided list until the approval reaches
   * a terminal state. Resolves null on timeout — callers reload anyway
   * (cheap, idempotent, covers slow-worker races).
   */
  function waitForOutcome(
    id: number,
    opts?: { timeoutMs?: number; intervalMs?: number }
  ): Promise<Approval | null> {
    const timeoutMs = opts?.timeoutMs ?? APPROVAL_OUTCOME_TIMEOUT_MS
    const intervalMs = opts?.intervalMs ?? APPROVAL_OUTCOME_POLL_INTERVAL_MS
    return new Promise<Approval | null>((resolve) => {
      let settled = false
      const done = (value: Approval | null): void => {
        if (settled) {
          return
        }
        settled = true
        clearInterval(timer)
        clearTimeout(killer)
        resolve(value)
      }
      const check = async (): Promise<void> => {
        try {
          const data = await approvalsApi.list('decided')
          const found = data.approvals.find((row) => row.id === id)
          if (found && TERMINAL_APPROVAL_STATUSES.has(found.status)) {
            done(found)
          }
        } catch {
          // Transient — the next tick retries; the timeout still bounds the wait.
        }
      }
      const timer = setInterval(() => void check(), intervalMs)
      const killer = setTimeout(() => done(null), timeoutMs)
      void check()
    })
  }

  async function subscribe(userId: number | null | undefined): Promise<void> {
    if (!isApprovalsEnabled() || !isRealtimeEnabled() || userId == null || userId <= 0) {
      return
    }
    if (subscribedUserId.value === userId) {
      return
    }
    unsubscribe()
    subscribedUserId.value = userId
    try {
      handle = await useRealtimeStore()
        .getOrCreateClient()
        .subscribe(`user:${userId}`, {
          onPublication: (envelope) => {
            if (envelope.type === 'approval.pending' || envelope.type === 'approval.decided') {
              void load('pending')
            } else if (envelope.type === 'approval.executed' && decided.value.length > 0) {
              void load('decided')
            } else if (envelope.type === APPROVAL_CHAT_CONTINUED_EVENT) {
              ingestContinuationEvent(envelope.data)
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
    pending,
    decided,
    pendingCount,
    loading,
    hasPending,
    load,
    addFromStream,
    approve,
    reject,
    onChatContinued,
    ingestContinuationEvent,
    waitForOutcome,
    subscribe,
    unsubscribe,
  }
})
