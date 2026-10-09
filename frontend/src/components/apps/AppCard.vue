<script setup lang="ts">
import { Icon } from '@iconify/vue'

withDefaults(
  defineProps<{
    to: string
    name: string
    tagline: string
    icon: string
    connected?: boolean
    testId?: string
  }>(),
  { connected: false, testId: undefined }
)
</script>

<template>
  <router-link
    :to="to"
    class="surface-card p-4 flex items-start gap-3 min-w-0 rounded-2xl border border-light-border/30 dark:border-dark-border/20 transition-colors hover:border-[var(--brand)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
    :data-testid="testId"
  >
    <span
      class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[var(--brand-alpha-light)] txt-brand"
      aria-hidden="true"
    >
      <Icon :icon="icon" class="w-6 h-6" />
    </span>
    <span class="min-w-0 flex-1">
      <span class="flex items-center gap-2 min-w-0">
        <span class="font-medium txt-primary truncate">{{ name }}</span>
        <span
          v-if="connected"
          class="shrink-0 px-2 py-0.5 rounded-full text-[11px] font-medium bg-[var(--status-success-muted)] text-[var(--status-success-text)]"
          data-testid="badge-app-connected"
        >
          {{ $t('apps.connected') }}
        </span>
      </span>
      <span class="block text-sm txt-secondary mt-0.5 line-clamp-2">{{ tagline }}</span>
    </span>
  </router-link>
</template>
