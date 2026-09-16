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

export const useApprovalsStore = defineStore('approvals', () => {
  const pending = ref<Approval[]>([])
  const decided = ref<Approval[]>([])
  const pendingCount = ref(0)
  const loading = ref(false)
  let handle: { unsubscribe: () => void } | null = null
  const subscribedUserId = ref<number | null>(null)

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
    subscribe,
    unsubscribe,
  }
})
