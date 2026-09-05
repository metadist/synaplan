<template>
  <fieldset class="space-y-2" data-testid="iam-permission-select">
    <legend class="text-sm font-medium txt-primary mb-1">{{ $t('iam.dialog.permission') }}</legend>
    <label
      v-for="level in levels"
      :key="level"
      class="flex items-center gap-2 text-sm txt-primary cursor-pointer"
    >
      <input v-model="selected" type="radio" class="accent-[var(--brand)]" :value="level" />
      {{ $t(`iam.permission.${level}`) }}
    </label>
  </fieldset>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  modelValue: string
  allowed: string[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const levels = computed(() =>
  ['read', 'use', 'edit', 'manage'].filter((level) => props.allowed.includes(level))
)

const selected = computed({
  get: () => props.modelValue,
  set: (value: string) => emit('update:modelValue', value),
})
</script>
