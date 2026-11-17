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

const props = defineProps<{
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

/**
 * The parent decides: it may ask first, refuse, or save. Until the prop
 * changes, the select keeps showing the current value, so a cancelled
 * change needs no reload.
 */
const onChange = (event: Event) => {
  const select = event.target as HTMLSelectElement
  const value = select.value
  select.value = props.modelValue
  if (value === 'auto' || value === 'approve' || value === 'block') emit('update:modelValue', value)
}
</script>
