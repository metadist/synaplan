<template>
  <span class="inline-flex items-center gap-2">
    <Icon
      :icon="ICONS[modelValue]"
      class="w-5 h-5 flex-shrink-0 txt-secondary"
      aria-hidden="true"
    />
    <select
      :value="modelValue"
      :disabled="disabled"
      :aria-label="label"
      class="px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-60 disabled:cursor-not-allowed"
      :data-testid="testid"
      @change="onChange"
    >
      <option v-if="modelValue === 'mixed'" value="mixed" disabled>
        {{ $t('mcpServers.permissions.outcome.mixed') }}
      </option>
      <option v-for="outcome in OUTCOMES" :key="outcome" :value="outcome">
        {{ $t(`mcpServers.permissions.outcome.${outcome}`) }}
      </option>
    </select>
  </span>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { OUTCOMES, type ToolOutcome } from './toolPermissions'

defineProps<{
  modelValue: ToolOutcome | 'mixed'
  disabled?: boolean
  label: string
  testid?: string
}>()

const emit = defineEmits<{ 'update:modelValue': [outcome: ToolOutcome] }>()

const ICONS: Record<ToolOutcome | 'mixed', string> = {
  auto: 'heroicons:check-circle',
  approve: 'heroicons:hand-raised',
  block: 'heroicons:no-symbol',
  mixed: 'heroicons:minus-circle',
}

const onChange = (event: Event) => {
  const value = (event.target as HTMLSelectElement).value
  if (value === 'auto' || value === 'approve' || value === 'block') emit('update:modelValue', value)
}
</script>
