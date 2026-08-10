<script setup lang="ts">
/**
 * The dropdown shape shared by every gateway mode setting. An option the
 * install cannot honour stays visible but disabled, so the operator learns the
 * capability exists instead of wondering where it went.
 */
defineProps<{
  modelValue: string
  options: { value: string; label: string; disabled?: boolean }[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()
</script>

<template>
  <select
    :value="modelValue"
    class="w-full sm:w-56 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
    @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
  >
    <option
      v-for="option in options"
      :key="option.value"
      :value="option.value"
      :disabled="option.disabled"
    >
      {{ option.label }}
    </option>
  </select>
</template>
