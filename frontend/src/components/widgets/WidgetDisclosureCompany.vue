<template>
  <div>
    <label class="block text-sm font-medium txt-primary mb-1" for="disclosure-company">
      {{ t('widgets.advancedConfig.disclosureCompany') }}
    </label>
    <p class="text-xs txt-secondary mb-2">
      {{ t('widgets.advancedConfig.disclosureCompanyHelp') }}
    </p>
    <input
      id="disclosure-company"
      :value="modelValue"
      type="text"
      maxlength="120"
      :placeholder="t('widgets.advancedConfig.disclosureCompanyPlaceholder')"
      class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
      data-testid="input-disclosure-company"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{ modelValue?: string }>()
const emit = defineEmits<{ 'update:modelValue': [string] }>()
const { t } = useI18n()
</script>
