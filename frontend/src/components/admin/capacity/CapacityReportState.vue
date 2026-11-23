<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'

/**
 * Loading, shared-cache-down and failed states of one Capacity tab. The
 * default slot renders once a report is there.
 */
defineProps<{
  loading: boolean
  unavailable: boolean
  failed: boolean
  ready: boolean
  loadingText?: string
  unavailableText?: string
}>()

defineEmits<{ retry: [] }>()

const { t } = useI18n()
</script>

<template>
  <div
    v-if="loading && !ready"
    class="surface-card p-8 text-center"
    data-testid="state-capacity-loading"
  >
    <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto mb-4 txt-secondary" />
    <div class="txt-secondary">{{ loadingText ?? t('capacity.loading') }}</div>
  </div>

  <div
    v-else-if="unavailable"
    class="surface-card p-8 text-center space-y-4"
    data-testid="state-capacity-unavailable"
  >
    <p class="txt-primary">{{ unavailableText ?? t('capacity.unavailable') }}</p>
    <button
      type="button"
      class="btn-primary px-4 py-2.5 text-sm font-medium"
      data-testid="btn-capacity-retry"
      @click="$emit('retry')"
    >
      {{ t('capacity.checkAgain') }}
    </button>
  </div>

  <div
    v-else-if="failed"
    class="surface-card p-8 text-center space-y-4"
    data-testid="state-capacity-error"
  >
    <p class="txt-primary">{{ t('capacity.failed') }}</p>
    <button
      type="button"
      class="btn-primary px-4 py-2.5 text-sm font-medium"
      data-testid="btn-capacity-retry"
      @click="$emit('retry')"
    >
      {{ t('capacity.checkAgain') }}
    </button>
  </div>

  <slot v-else-if="ready" />
</template>
