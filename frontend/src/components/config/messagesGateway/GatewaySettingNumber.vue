<script setup lang="ts">
/**
 * A bounded numeric gateway setting.
 *
 * The value is clamped on commit rather than rejected: the backend enforces the
 * same range, and silently correcting a typo is friendlier than an error toast
 * for a number nobody meant to type. Nothing is emitted when the committed
 * value already matches, so tabbing through the field saves nothing.
 */
import { ref, watch } from 'vue'

const props = defineProps<{
  modelValue: number
  min: number
  max: number
  disabled?: boolean
}>()

const emit = defineEmits<{
  change: [value: number]
}>()

const draft = ref(props.modelValue)

watch(
  () => props.modelValue,
  (value) => {
    draft.value = value
  }
)

function commit() {
  const raw = Number(draft.value)
  const value = Number.isFinite(raw)
    ? Math.min(props.max, Math.max(props.min, Math.round(raw)))
    : props.min

  draft.value = value
  if (value === props.modelValue) return

  emit('change', value)
}
</script>

<template>
  <input
    v-model.number="draft"
    type="number"
    :min="min"
    :max="max"
    :disabled="disabled"
    class="w-24 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm text-right focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:cursor-not-allowed"
    @change="commit"
  />
</template>
