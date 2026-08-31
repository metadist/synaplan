<template>
  <!-- `whitespace-nowrap` belongs on the label, not here: on the outer element
       it kept the pill at its full text width, so the inner `truncate` never
       took effect and a long folder name ran underneath the row's action
       icons. -->
  <span
    v-if="visible"
    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium min-w-0 max-w-full overflow-hidden"
    :class="variant.classes"
    :title="tooltip"
    :aria-label="label"
    data-testid="file-vector-pill"
  >
    <Icon :icon="variant.icon" class="w-3 h-3 shrink-0" :class="variant.spin && 'animate-spin'" />
    <span class="truncate whitespace-nowrap">{{ label }}</span>
  </span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
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

// "Not searchable" is a real state the user must see — hiding it made two
// otherwise identical rows (e.g. voice memos) look the same.
const visible = computed(() =>
  ['vectorized', 'pending', 'failed', 'none', 'not_applicable'].includes(props.state)
)

// Visual variants per 03_file-management.md §5.1.2.B — standard palette
// utilities (already used across the files UI), dark-mode safe.
const variant = computed(() => {
  switch (props.state) {
    case 'vectorized':
      return {
        classes: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400',
        icon: 'mdi:check-circle',
        spin: false,
      }
    case 'pending':
      return {
        classes: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
        icon: 'mdi:loading',
        spin: true,
      }
    case 'failed':
      return {
        classes: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        icon: 'mdi:alert-circle',
        spin: false,
      }
    default:
      return {
        classes: 'bg-black/[0.06] txt-secondary dark:bg-white/[0.08]',
        icon: 'mdi:circle-outline',
        spin: false,
      }
  }
})

const label = computed(() => {
  switch (props.state) {
    case 'vectorized':
      if (props.groupKey && props.chunkCount > 0) {
        return t('files.vectorState.vectorizedDetail', {
          group: props.groupKey,
          count: props.chunkCount,
        })
      }
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
