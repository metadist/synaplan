<script setup lang="ts">
/**
 * On/off control for a single gateway setting.
 */
defineProps<{
  modelValue: boolean
  label: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

function toggle(current: boolean, disabled: boolean | undefined) {
  if (disabled) return
  emit('update:modelValue', !current)
}
</script>

<template>
  <div class="inline-flex items-center gap-3">
    <!--
      The track colour lives on an inner element on purpose: the V2 design sets
      a flat background on every `[role="switch"]`, which would beat a utility
      class on the button itself and make on and off look identical.
    -->
    <button
      type="button"
      role="switch"
      :aria-checked="modelValue"
      :aria-label="label"
      :disabled="disabled"
      :class="[
        'relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full bg-transparent focus:outline-none focus:ring-2 focus:ring-[var(--brand)] focus:ring-offset-2',
        disabled ? 'cursor-not-allowed' : 'cursor-pointer',
      ]"
      @click="toggle(modelValue, disabled)"
    >
      <span
        :class="[
          'pointer-events-none absolute inset-0 rounded-full transition-colors duration-200 ease-in-out',
          modelValue ? 'bg-[var(--brand)]' : 'bg-[var(--status-neutral)]',
        ]"
      />
      <span
        :class="[
          'pointer-events-none relative inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
          modelValue ? 'translate-x-5' : 'translate-x-0.5',
        ]"
      />
    </button>
    <span class="text-sm txt-secondary w-16 text-left">
      {{ modelValue ? $t('common.enabled') : $t('common.disabled') }}
    </span>
  </div>
</template>
