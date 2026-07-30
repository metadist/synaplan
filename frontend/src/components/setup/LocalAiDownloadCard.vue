<template>
  <div v-if="visible" data-testid="local-ai-download-card">
    <div
      class="rounded-lg border border-[var(--border-subtle)] bg-[var(--bg-card)] p-4 flex items-start gap-3"
    >
      <Icon
        :icon="isError ? 'mdi:alert-circle-outline' : 'mdi:cloud-download-outline'"
        class="w-6 h-6 shrink-0 mt-0.5"
        :class="isError ? 'text-[var(--status-error)]' : 'txt-brand'"
      />
      <div class="flex-1 min-w-0">
        <p class="font-semibold txt-primary">{{ title }}</p>
        <p class="text-sm txt-secondary mt-0.5">{{ subtitle }}</p>
        <div
          v-if="showProgress"
          class="mt-3 h-2 rounded-full bg-[var(--bg-muted)] overflow-hidden"
          role="progressbar"
          :aria-valuenow="percent ?? 0"
          aria-valuemin="0"
          aria-valuemax="100"
        >
          <div
            class="h-full rounded-full bg-[var(--brand)] transition-[width] duration-500"
            :style="{ width: `${percent ?? 0}%` }"
          />
        </div>
      </div>
      <button
        class="txt-secondary hover:txt-primary shrink-0"
        :aria-label="$t('common.close')"
        data-testid="local-ai-download-dismiss"
        @click="dismissed = true"
      >
        <Icon icon="mdi:close" class="w-5 h-5" />
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import {
  getLocalAiDownloadStatus,
  type LocalAiDownloadStatus,
} from '@/services/api/localAiStatusApi'

const POLL_MS = 5000

const { t } = useI18n()
const authStore = useAuthStore()

const dismissed = ref(false)
const status = ref<LocalAiDownloadStatus | null>(null)
let timer: ReturnType<typeof setInterval> | null = null

const isActive = computed(() => {
  const s = status.value?.status
  return s === 'waiting' || s === 'downloading' || s === 'error'
})

const visible = computed(() => !dismissed.value && authStore.isAuthenticated && isActive.value)

const isError = computed(() => status.value?.status === 'error')
const percent = computed(() => status.value?.percent)
const showProgress = computed(
  () => !isError.value && status.value?.status === 'downloading' && percent.value != null
)

const title = computed(() => {
  if (isError.value) return t('localAiDownload.errorTitle')
  if (status.value?.status === 'waiting') return t('localAiDownload.waitingTitle')
  return t('localAiDownload.downloadingTitle', { percent: percent.value ?? 0 })
})

const subtitle = computed(() => {
  if (isError.value) {
    return status.value?.message || t('localAiDownload.errorText')
  }
  return t('localAiDownload.cloudWorksText')
})

const refresh = async () => {
  if (!authStore.isAuthenticated) return
  try {
    status.value = await getLocalAiDownloadStatus()
    if (status.value.status === 'ready' || status.value.status === 'idle') {
      stopPolling()
    }
  } catch {
    // Silent — banner is optional UX; chat still works without it.
  }
}

const stopPolling = () => {
  if (timer) {
    clearInterval(timer)
    timer = null
  }
}

onMounted(() => {
  void refresh()
  timer = setInterval(() => {
    void refresh()
  }, POLL_MS)
})

onUnmounted(() => {
  stopPolling()
})
</script>
