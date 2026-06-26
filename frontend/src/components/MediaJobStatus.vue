<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import type { MediaJobInfo } from '@/stores/history'

const props = defineProps<{
  mediaJob: MediaJobInfo
  modelLabel?: string
}>()

const iconForType = computed(() => {
  switch (props.mediaJob.type) {
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

const titleKey = computed(() => `message.mediaJob.title.${props.mediaJob.type}`)
</script>

<template>
  <div
    class="media-job-status rounded-xl border p-4"
    role="status"
    :aria-label="$t(titleKey)"
    data-testid="media-job-status"
  >
    <div class="flex items-start gap-3">
      <Icon
        :icon="iconForType"
        class="w-6 h-6 flex-shrink-0"
        style="color: var(--brand)"
        aria-hidden="true"
      />
      <div class="flex-1 min-w-0 space-y-1.5">
        <div class="flex items-center gap-2">
          <Icon
            icon="mdi:loading"
            class="w-4 h-4 animate-spin flex-shrink-0"
            style="color: var(--brand)"
            aria-hidden="true"
          />
          <p class="text-sm font-medium txt-primary">{{ $t(titleKey) }}</p>
        </div>
        <p class="text-sm txt-muted leading-relaxed">{{ $t('message.mediaJob.backgroundHint') }}</p>
        <p v-if="modelLabel" class="text-xs txt-muted">
          {{ $t('message.mediaJob.model', { model: modelLabel }) }}
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.media-job-status {
  background: var(--surface-card, rgba(127, 127, 127, 0.04));
  border-color: var(--brand);
}
</style>
