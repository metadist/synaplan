<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import ApprovalCard from '@/components/chat/ApprovalCard.vue'
import { useApprovalsStore } from '@/stores/approvals'
import { useNotification } from '@/composables/useNotification'
import { approvalsApi, type Approval } from '@/services/api/approvalsApi'
import { isApprovalsEnabled } from '@/composables/useApprovalsFeature'

const { t, te } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useApprovalsStore()
const { success, error: showError } = useNotification()
const tab = ref<'pending' | 'decided'>('pending')
const notifyMode = ref<'instant' | 'digest'>('instant')

onMounted(async () => {
  if (!isApprovalsEnabled()) {
    return
  }
  await store.load('pending')
  try {
    notifyMode.value = await approvalsApi.getNotifyMode()
  } catch {
    // Setting is optional; inbox still works.
  }
})

const taskFilter = computed(() => {
  const raw = route.query.task
  if (typeof raw !== 'string' || raw === '') return null
  const id = Number(raw)
  return Number.isFinite(id) ? id : null
})

const rows = computed(() => {
  const list = tab.value === 'pending' ? store.pending : store.decided
  if (taskFilter.value === null) return list
  return list.filter((row) => row.requestedBy.taskId === taskFilter.value)
})

const switchTab = async (next: 'pending' | 'decided') => {
  tab.value = next
  await store.load(next)
}

const statusLabel = (status: string): string => {
  const key = `approvals.status.${status}`
  return te(key) ? t(key) : status
}

const openContext = (approval: Approval) => {
  const ref = approval.requestedBy
  if (ref.kind === 'chat' && ref.chatId) {
    void router.push({ path: '/', query: { chat: String(ref.chatId) } })
    return
  }
  if (ref.kind === 'task_run' && ref.taskId) {
    void router.push({
      path: '/channels/tasks',
      query: { task: String(ref.taskId), run: String(ref.runId ?? '') },
    })
  }
}

const onApprove = async (id: number, alwaysAllow = false) => {
  try {
    await store.approve(id, alwaysAllow)
    success(t('approvals.approvedToast'))
  } catch {
    showError(t('approvals.decideFailed'))
  }
}

const onReject = async (id: number, reason?: string) => {
  try {
    await store.reject(id, reason)
    success(t('approvals.rejectedToast'))
  } catch {
    showError(t('approvals.decideFailed'))
  }
}

const onNotifyChange = async () => {
  try {
    notifyMode.value = await approvalsApi.setNotifyMode(notifyMode.value)
    success(t('approvals.notifySaved'))
  } catch {
    showError(t('approvals.decideFailed'))
  }
}
</script>

<template>
  <div v-if="isApprovalsEnabled()" class="space-y-6" data-testid="page-approvals">
    <PageHeader
      :title="$t('approvals.title')"
      :subtitle="$t('approvals.subtitle')"
      icon="heroicons:check-badge"
    />

    <label class="block text-sm txt-primary max-w-md">
      {{ $t('approvals.notify') }}
      <select
        v-model="notifyMode"
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="approvals-notify-mode"
        @change="onNotifyChange"
      >
        <option value="instant">{{ $t('approvals.notifyInstant') }}</option>
        <option value="digest">{{ $t('approvals.notifyDigest') }}</option>
      </select>
    </label>

    <div class="flex gap-2">
      <button
        type="button"
        class="px-4 py-2.5 rounded-lg text-sm font-medium"
        :class="tab === 'pending' ? 'btn-primary' : 'btn-secondary'"
        data-testid="approvals-tab-pending"
        @click="switchTab('pending')"
      >
        {{ $t('approvals.pending') }}
      </button>
      <button
        type="button"
        class="px-4 py-2.5 rounded-lg text-sm font-medium"
        :class="tab === 'decided' ? 'btn-primary' : 'btn-secondary'"
        data-testid="approvals-tab-decided"
        @click="switchTab('decided')"
      >
        {{ $t('approvals.decided') }}
      </button>
    </div>

    <p
      v-if="tab === 'pending' && rows.length === 0 && !store.loading"
      class="txt-secondary text-sm"
      data-testid="approvals-empty"
    >
      {{ $t('approvals.emptyPending') }}
    </p>
    <p
      v-else-if="tab === 'decided' && rows.length === 0 && !store.loading"
      class="txt-secondary text-sm"
    >
      {{ $t('approvals.emptyDecided') }}
    </p>

    <ul class="space-y-3">
      <li v-for="row in rows" :key="row.id">
        <ApprovalCard
          v-if="tab === 'pending'"
          :approval="row"
          :can-always-allow="row.canAlwaysAllow"
          @approved="onApprove(row.id)"
          @rejected="onReject"
          @always-allow="onApprove(row.id, true)"
        />
        <div v-else class="surface-card p-4 space-y-1">
          <p class="txt-primary font-medium">{{ row.preview || row.tool }}</p>
          <p class="text-xs txt-secondary">{{ statusLabel(row.status) }}</p>
        </div>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium mt-2"
          @click="openContext(row)"
        >
          {{ $t('approvals.openContext') }}
        </button>
      </li>
    </ul>
  </div>
</template>
