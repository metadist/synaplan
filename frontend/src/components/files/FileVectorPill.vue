<template>
  <span
    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap"
    :class="variant.classes"
    :title="tooltip"
    :aria-label="label"
    data-testid="file-vector-pill"
  >
    <Icon :icon="variant.icon" class="w-3 h-3 shrink-0" :class="variant.spin && 'animate-spin'" />
    <span class="truncate">{{ label }}</span>
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
    case 'not_applicable':
      return {
        classes: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
        icon: 'mdi:minus-circle-outline',
        spin: false,
      }
    default:
      return {
        classes: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
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
    case 'not_applicable':
      return t('files.vectorState.notApplicable')
    default:
      return t('files.vectorState.none')
  }
})

const tooltip = computed(() => {
  if (props.state === 'not_applicable') {
    return t('files.vectorState.notApplicable')
  }
  return t('files.help.vectorized')
})
</script>
