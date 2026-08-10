<script setup lang="ts">
/**
 * One labelled gateway setting: explanation on the left, control on the right.
 *
 * `note` is for the state that makes a setting inert right now (no search
 * provider, no MCP server, a parent switch turned off) — the operator sees why
 * before they change something that cannot take effect.
 */
import { Icon } from '@iconify/vue'

defineProps<{
  label: string
  description: string
  note?: string
  disabled?: boolean
}>()
</script>

<template>
  <div
    class="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-8"
    :class="disabled && 'opacity-60'"
  >
    <div class="min-w-0 sm:max-w-lg">
      <p class="text-sm font-medium txt-primary">{{ label }}</p>
      <p class="text-xs txt-secondary mt-1 leading-relaxed">{{ description }}</p>
      <p
        v-if="note"
        class="text-xs mt-1.5 flex items-start gap-1.5 text-amber-600 dark:text-amber-400"
      >
        <Icon icon="heroicons:information-circle" class="w-4 h-4 flex-shrink-0" />
        <span>{{ note }}</span>
      </p>
    </div>
    <div class="flex-shrink-0 sm:min-w-[13rem] sm:text-right">
      <slot />
    </div>
  </div>
</template>
