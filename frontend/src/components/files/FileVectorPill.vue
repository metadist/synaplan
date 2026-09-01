<template>
  <!-- Icon-only status: colour carries the meaning (green = searchable,
       grey = not yet), the label lives in the hover tooltip. A text pill was
       too loud — the default "not searchable" state applies to most rows, so
       spelling it out on every row was noise and could wrap on mobile. -->
  <span
    class="inline-flex items-center shrink-0"
    :class="variant.color"
    :title="tooltip"
    :aria-label="label"
    role="img"
    data-testid="file-vector-pill"
  >
    <Icon :icon="variant.icon" class="w-4 h-4" :class="variant.spin && 'animate-spin'" />
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

// Colour + icon per state. Standard palette utilities, dark-mode safe.
const variant = computed(() => {
  switch (props.state) {
    case 'vectorized':
      return {
        color: 'text-emerald-600 dark:text-emerald-400',
        icon: 'mdi:check-circle',
        spin: false,
      }
    case 'pending':
      return {
        color: 'text-blue-500 dark:text-blue-400',
        icon: 'mdi:loading',
        spin: true,
      }
    case 'failed':
      return {
        color: 'text-red-500 dark:text-red-400',
        icon: 'mdi:alert-circle',
        spin: false,
      }
    default:
      return {
        color: 'txt-secondary',
        icon: 'mdi:circle-outline',
        spin: false,
      }
  }
})

// Short label for screen readers (aria-label).
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

// Hover tooltip carries the detail (folder + chunk count when searchable).
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
