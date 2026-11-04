<template>
  <!-- Word plus colour: the status must be readable without a hover. The
       glyph is extra on larger screens; on a phone it drops so the row
       keeps the label on one line. -->
  <span
    class="inline-flex items-center gap-1 min-w-0 max-w-full overflow-hidden text-[11px] font-medium"
    :class="variant.color"
    :title="tooltip"
    data-testid="file-vector-pill"
  >
    <CloudGlyph
      v-if="variant.glyph"
      class="hidden sm:inline-flex shrink-0"
      :eyes="false"
      :badge="variant.badge"
      :size="16"
      :class="variant.spin && 'animate-spin'"
    />
    <Icon v-else :icon="variant.icon" class="hidden sm:block w-3.5 h-3.5 shrink-0" />
    <span class="truncate whitespace-nowrap">{{ label }}</span>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import CloudGlyph from '@/components/files/CloudGlyph.vue'
import type { FileVectorState } from '@/services/filesService'

const props = withDefaults(
  defineProps<{
    state?: FileVectorState
    chunkCount?: number
    groupKey?: string | null
  }>(),
  {
    state: 'none',
    chunkCount: 0,
    groupKey: null,
  }
)

const { t } = useI18n()

const variant = computed(() => {
  switch (props.state) {
    case 'vectorized':
      return {
        color: 'text-[var(--status-success-text)]',
        icon: 'mdi:check-circle',
        glyph: true,
        badge: null,
        spin: false,
      }
    case 'pending':
      return {
        color: 'text-[var(--status-info)]',
        icon: 'mdi:loading',
        glyph: true,
        badge: 'clock' as const,
        spin: true,
      }
    case 'failed':
      return {
        color: 'text-[var(--status-error)]',
        icon: 'mdi:alert-circle',
        glyph: true,
        badge: 'alert' as const,
        spin: false,
      }
    default:
      return {
        color: 'txt-secondary',
        icon: 'mdi:circle-outline',
        glyph: false,
        badge: null,
        spin: false,
      }
  }
})

const label = computed(() => {
  switch (props.state) {
    case 'vectorized':
      return t('files.vectorState.vectorized')
    case 'pending':
      return t('files.vectorState.processing')
    case 'failed':
      return t('files.vectorState.failed')
    default:
      return t('files.vectorState.none')
  }
})

const tooltip = computed(() => {
  switch (props.state) {
    case 'vectorized':
      if (props.groupKey && props.chunkCount > 0) {
        return t('files.vectorState.vectorizedDetail', {
          group: props.groupKey,
          count: props.chunkCount,
        })
      }
      return t('files.help.vectorized')
    case 'pending':
      return t('files.help.processing')
    case 'failed':
      return t('files.help.failed')
    default:
      return t('files.help.notSearchable')
  }
})
</script>
