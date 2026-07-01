<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import type { TrayJob } from '@/stores/mediaJobs'

const props = defineProps<{
  job: TrayJob
  chatTitle?: string
}>()

const emit = defineEmits<{
  open: [chatId: number]
  cancel: [jobId: string]
}>()

const iconForType = computed(() => {
  switch (props.job.type) {
    case 'video':
      return 'mdi:video-outline'
    case 'image':
      return 'mdi:image-outline'
    case 'audio':
      return 'mdi:waveform'
    default:
      return 'mdi:cog-outline'
  }
})

const percent = computed(() => {
  const raw = props.job.percent
  if (raw === undefined || raw === null) return null
  return Math.max(0, Math.min(100, Math.round(raw)))
})
</script>

<template>
  <div
    class="surface-card rounded-lg border border-[var(--border-light)] p-3"
    data-testid="job-row"
  >
    <div class="flex items-start gap-2.5">
      <Icon :icon="iconForType" class="w-5 h-5 flex-shrink-0 text-brand" aria-hidden="true" />

      <div class="flex-1 min-w-0">
        <p class="text-sm font-medium txt-primary truncate" :title="job.prompt">
          {{ job.prompt || $t(`jobs.kind.${job.type}`) }}
        </p>
        <p v-if="chatTitle" class="text-xs txt-muted truncate">{{ chatTitle }}</p>

        <div v-if="percent !== null" class="mt-1.5">
          <div class="h-1 w-full overflow-hidden rounded-full bg-[var(--border-light)]">
            <div
              class="h-full rounded-full bg-brand transition-[width] duration-300 ease-out"
              :style="{ width: `${percent}%` }"
            />
          </div>
          <p class="text-xs txt-muted mt-0.5">{{ $t('message.mediaJob.progress', { percent }) }}</p>
        </div>
        <p v-else class="text-xs txt-muted mt-0.5">{{ $t('jobs.row.working') }}</p>
      </div>

      <div class="flex flex-shrink-0 items-center gap-1">
        <button
          v-if="job.chatId != null"
          type="button"
          class="rounded-md px-2 py-1 text-xs text-brand hover:bg-black/5 dark:hover:bg-white/5"
          data-testid="job-row-open"
          @click="emit('open', job.chatId)"
        >
          {{ $t('jobs.row.open') }}
        </button>
        <button
          type="button"
          class="rounded-md px-2 py-1 text-xs text-danger hover:bg-black/5 dark:hover:bg-white/5"
          data-testid="job-row-stop"
          @click="emit('cancel', job.jobId)"
        >
          {{ $t('jobs.cancel.stop') }}
        </button>
      </div>
    </div>
  </div>
</template>
