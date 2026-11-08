<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import { isSavedTasksEnabled } from '@/composables/useSavedTasksFeature'
import ApprovalCard from '@/components/chat/ApprovalCard.vue'
import { useApprovalsStore } from '@/stores/approvals'
import { useNotification } from '@/composables/useNotification'
import { approvalsApi, type Approval } from '@/services/api/approvalsApi'
import { useDateFormat } from '@/composables/useDateFormat'
import { isApprovalsEnabled } from '@/composables/useApprovalsFeature'

const { t, te } = useI18n()
const { formatDateTime } = useDateFormat()
const route = useRoute()
const router = useRouter()
const store = useApprovalsStore()
const { success, error: showError } = useNotification()
const tab = ref<'pending' | 'decided'>('pending')
const notifyMode = ref<'instant' | 'digest'>('instant')
const mailConfigured = ref(true)

onMounted(async () => {
  if (!isApprovalsEnabled()) {
    return
  }
  await openFocusedApproval()
  try {
    const setting = await approvalsApi.getNotifyMode()
    notifyMode.value = setting.mode
    mailConfigured.value = setting.mailConfigured
  } catch {
    // Setting is optional; inbox still works.
  }
})

const focusedApproval = ref<Approval | null>(null)

const focusedApprovalId = computed(() => {
  const raw = route.query.approval
  if (typeof raw !== 'string' || raw === '') return null
  const id = Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
})

let focusRequest = 0

async function openFocusedApproval(): Promise<void> {
  const request = ++focusRequest
  const id = focusedApprovalId.value
  if (id === null) {
    focusedApproval.value = null
    tab.value = 'pending'
    await store.load('pending')
    return
  }
  try {
    const approval = await approvalsApi.get(id)
    if (request !== focusRequest) return
    tab.value = approval.status === 'pending' ? 'pending' : 'decided'
    await store.load(tab.value)
    if (request !== focusRequest) return
    const source = tab.value === 'pending' ? store.pending : store.decided
    focusedApproval.value = source.find((row) => row.id === approval.id) ?? approval
  } catch {
    if (request !== focusRequest) return
    focusedApproval.value = null
    await store.load(tab.value)
  }
}

watch(focusedApprovalId, () => {
  void openFocusedApproval()
})

function adoptFresher(): void {
  const focused = focusedApproval.value
  if (focused === null) return
  const decided = store.decided.find((row) => row.id === focused.id)
  if (decided && decided.status !== 'pending' && focused.status === 'pending') {
    focusedApproval.value = decided
    return
  }
  const source = focused.status === 'pending' ? store.pending : store.decided
  const here = source.find((row) => row.id === focused.id)
  if (here) focusedApproval.value = here
}

watch([() => store.pending, () => store.decided], () => {
  adoptFresher()
})

const taskFilter = computed(() => {
  const raw = route.query.task
  if (typeof raw !== 'string' || raw === '') return null
  const id = Number(raw)
  return Number.isFinite(id) ? id : null
})

const rows = computed(() => {
  const source = tab.value === 'pending' ? store.pending : store.decided
  const focused = focusedApproval.value
  const stored = focused ? source.find((row) => row.id === focused.id) : undefined
  const stalePending =
    tab.value === 'pending' &&
    focused !== null &&
    focused.status !== 'pending' &&
    stored?.status === 'pending'
  const visible = stalePending ? source.filter((row) => row.id !== focused.id) : source
  const head = stalePending ? focused : (stored ?? focused)
  const onThisTab =
    head !== null && head !== undefined && (tab.value === 'pending') === (head.status === 'pending')
  const list = onThisTab ? [head, ...visible.filter((row) => row.id !== head.id)] : visible
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
      path: '/tasks',
      query: { task: String(ref.taskId), run: String(ref.runId ?? '') },
    })
  }
}

const onApprove = async (id: number, alwaysAllow = false) => {
  try {
    await store.approve(id, alwaysAllow)
    if (focusedApproval.value?.id === id) focusedApproval.value = null
    success(t('approvals.approvedToast'))
  } catch {
    showError(t('approvals.decideFailed'))
  }
}

const onReject = async (id: number, reason?: string) => {
  try {
    await store.reject(id, reason)
    if (focusedApproval.value?.id === id) focusedApproval.value = null
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

const decidedLine = (row: Approval): string => {
  const when = row.decidedAt ? formatDateTime(new Date(row.decidedAt * 1000)) : ''
  if (!row.decidedByName && !when) return ''
  return t('approvals.decidedLine', {
    status: statusLabel(row.status),
    name: row.decidedByName ?? '',
    time: when,
  })
}
</script>

<template>
  <div v-if="isApprovalsEnabled()" class="space-y-6" data-testid="page-approvals">
    <PageHeader
      :title="$t('approvals.title')"
      :subtitle="$t('approvals.subtitle')"
      icon="heroicons:check-badge"
      tour-id="approvals"
    />

    <label class="block text-sm txt-primary max-w-md">
      {{ $t('approvals.notify') }}
      <select
        v-model="notifyMode"
        class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="approvals-notify-mode"
        @change="onNotifyChange"
      >
        <option value="instant">{{ $t('approvals.notifyInstant') }}</option>
        <option value="digest">{{ $t('approvals.notifyDigest') }}</option>
      </select>
    </label>
    <p
      v-if="!mailConfigured"
      class="text-sm txt-secondary"
      data-testid="approvals-mail-unconfigured"
    >
      {{ $t('approvals.mailNotConfigured') }}
    </p>

    <div class="flex gap-2">
      <button
        type="button"
        class="px-4 py-2.5 rounded-xl text-sm font-medium"
        :class="tab === 'pending' ? 'btn-primary' : 'btn-secondary'"
        data-testid="approvals-tab-pending"
        @click="switchTab('pending')"
      >
        {{ $t('approvals.pending') }}
      </button>
      <button
        type="button"
        class="px-4 py-2.5 rounded-xl text-sm font-medium"
        :class="tab === 'decided' ? 'btn-primary' : 'btn-secondary'"
        data-testid="approvals-tab-decided"
        @click="switchTab('decided')"
      >
        {{ $t('approvals.decided') }}
      </button>
    </div>

    <EmptyState
      v-if="tab === 'pending' && rows.length === 0 && !store.loading"
      :title="$t('approvals.emptyPending')"
      :action-label="isSavedTasksEnabled() ? $t('approvals.openTasks') : undefined"
      to="/tasks"
      test-id="approvals-empty"
      action-test-id="btn-approvals-open-tasks"
    />
    <p
      v-else-if="tab === 'decided' && rows.length === 0 && !store.loading"
      class="txt-secondary text-sm"
    >
      {{ $t('approvals.emptyDecided') }}
    </p>

    <ul class="space-y-3">
      <li v-for="row in rows" :key="row.id" :data-testid="`approval-row-${row.id}`">
        <ApprovalCard
          v-if="tab === 'pending'"
          :approval="row"
          :can-always-allow="row.canAlwaysAllow"
          show-open-context
          @approved="onApprove(row.id)"
          @rejected="onReject"
          @always-allow="onApprove(row.id, true)"
          @open-context="openContext(row)"
        />
        <div v-else class="surface-card p-4 space-y-2">
          <p class="txt-primary font-medium">{{ row.preview || row.tool }}</p>
          <p class="text-xs txt-secondary" data-testid="approval-decided-line">
            {{ decidedLine(row) || statusLabel(row.status) }}
          </p>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium"
            data-testid="approval-open-context"
            @click="openContext(row)"
          >
            {{ $t('approvals.openContext') }}
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>
