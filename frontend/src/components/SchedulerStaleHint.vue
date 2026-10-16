<template>
  <RouterLink
    v-if="schedulerStore.isStale"
    to="/admin/features"
    :class="
      compact
        ? 'flex flex-col items-center gap-0.5 w-full px-1 py-1 rounded-lg text-[10px] font-semibold leading-tight text-center transition-opacity hover:opacity-80 bg-[var(--status-error-muted)] text-[var(--status-error-text)]'
        : 'flex items-center gap-2 w-full min-h-[44px] px-3 py-2.5 rounded-lg text-sm font-medium bg-[var(--status-error-muted)] text-[var(--status-error-text)]'
    "
    :title="$t('sidebar.schedulerStopped')"
    :aria-label="$t('sidebar.schedulerStopped')"
    data-testid="link-sidebar-v2-scheduler"
  >
    <ExclamationTriangleIcon
      :class="compact ? 'w-4 h-4 flex-shrink-0' : 'w-5 h-5 flex-shrink-0'"
      aria-hidden="true"
    />
    <span :class="compact ? 'break-words' : 'min-w-0 flex-1 text-left'">
      {{ $t(compact ? 'sidebar.schedulerStoppedShort' : 'sidebar.schedulerStopped') }}
    </span>
  </RouterLink>
</template>

<script setup lang="ts">
import { ExclamationTriangleIcon } from '@heroicons/vue/24/outline'
import { useSchedulerStore } from '@/stores/scheduler'

withDefaults(defineProps<{ compact?: boolean }>(), { compact: false })

const schedulerStore = useSchedulerStore()
</script>
