<template>
  <label class="block">
    <span class="text-sm font-medium txt-primary">{{ $t('mcpServers.urlLabel') }}</span>
    <input
      :value="modelValue"
      type="url"
      placeholder="https://example.com/mcp"
      :readonly="locked"
      class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] font-mono"
      :class="locked ? 'opacity-80' : ''"
      data-testid="input-mcp-url"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
    <p
      v-if="dropsKey"
      class="text-xs txt-secondary mt-1 leading-relaxed"
      data-testid="mcp-address-drops-key"
    >
      {{ $t('mcpServers.addressDropsKey') }}
    </p>
  </label>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  modelValue: string
  locked: boolean
  /** Address the server was saved with; empty while adding a new one. */
  savedUrl: string
  hasSavedKey: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const originOf = (url: string): string => {
  try {
    return new URL(url.trim()).origin
  } catch {
    return ''
  }
}

// The backend drops a saved key when the host changes; say so before Save.
const dropsKey = computed(
  () =>
    props.savedUrl !== '' &&
    props.hasSavedKey &&
    originOf(props.modelValue) !== originOf(props.savedUrl)
)
</script>
