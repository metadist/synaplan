<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { AdminSearchConfig } from '@/services/api/adminSearchApi'

type Slot = AdminSearchConfig['ai']

const props = defineProps<{
  id: string
  label: string
  hint: string
  inheritLabel: string
  /** When set, every concrete model sits under this heading: one choice for everyone. */
  globalGroupLabel?: string
  slotConfig: Slot
  disabled?: boolean
}>()

const emit = defineEmits<{ change: [modelId: number | null] }>()

const { t } = useI18n()

const optionLabel = (option: Slot['options'][number]): string => {
  const base = `${option.name} (${option.service})`
  if (option.available) return base
  const reason =
    option.reason === 'not_pulled'
      ? t('aiInfra.searchModels.reason.notPulled')
      : t('aiInfra.searchModels.reason.providerUnavailable', { service: option.service })
  return `${base} — ${reason}`
}

const onChange = (event: Event) => {
  const select = event.target as HTMLSelectElement
  const raw = select.value
  // The stored choice stays shown until the parent confirms and saves.
  select.value =
    props.slotConfig.selectedModelId === null ? '' : String(props.slotConfig.selectedModelId)
  emit('change', raw === '' ? null : Number(raw))
}
</script>

<template>
  <div>
    <label class="block text-sm font-medium txt-primary" :for="id">{{ label }}</label>
    <select
      :id="id"
      :value="slotConfig.selectedModelId === null ? '' : String(slotConfig.selectedModelId)"
      :disabled="disabled"
      class="mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
      :data-testid="`select-${id}`"
      @change="onChange"
    >
      <option value="">{{ inheritLabel }}</option>
      <optgroup v-if="globalGroupLabel" :label="globalGroupLabel">
        <option
          v-for="option in slotConfig.options"
          :key="option.id"
          :value="String(option.id)"
          :disabled="!option.available"
        >
          {{ optionLabel(option) }}
        </option>
      </optgroup>
      <template v-else>
        <option
          v-for="option in slotConfig.options"
          :key="option.id"
          :value="String(option.id)"
          :disabled="!option.available"
        >
          {{ optionLabel(option) }}
        </option>
      </template>
    </select>
    <p class="text-xs txt-secondary mt-1">{{ hint }}</p>
  </div>
</template>
