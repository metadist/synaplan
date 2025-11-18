<template>
  <div class="relative">
    <select
      :value="modelValue"
      @change="$emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
      :class="[
        'w-full appearance-none rounded-lg surface-chip txt-primary px-4 py-2 pr-10 min-h-[44px] focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all',
        customClass,
      ]"
      data-testid="input-select"
    >
      <slot />
    </select>
    <ChevronDownIcon class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 txt-secondary pointer-events-none" />
  </div>
</template>

<script setup lang="ts">
import { ChevronDownIcon } from '@heroicons/vue/24/outline'

interface Props {
  modelValue: string
  customClass?: string
}

defineProps<Props>()
defineEmits<{
  'update:modelValue': [value: string]
}>()
</script>
