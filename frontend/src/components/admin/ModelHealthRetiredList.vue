<template>
  <AccordionStack testid="model-status-retired">
    <AccordionSection
      panel-id="model-status-retired"
      :title="$t('adminModelStatus.retired.title')"
      :open="open"
      testid="section-retired"
      header-testid="btn-retired"
      @toggle="open = !open"
    >
      <template #badge>
        <span
          class="px-2.5 py-1 rounded-md text-xs font-semibold bg-[var(--status-neutral-muted)] text-[var(--status-neutral-text)]"
        >
          {{ models.length }}
        </span>
      </template>

      <p class="txt-secondary text-sm mb-2">{{ $t('adminModelStatus.retired.hint') }}</p>

      <ul>
        <li
          v-for="model in models"
          :key="model.id"
          class="py-3 border-t border-light-border/30 dark:border-dark-border/20 first:border-t-0"
          data-testid="item-retired-model"
        >
          <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
            <span class="font-medium txt-primary">{{ model.name }}</span>
            <span class="text-xs txt-secondary">
              {{ model.providerDisplayName }} · {{ capabilityLabel(model.capability) }}
            </span>
          </div>
          <code class="text-xs txt-secondary font-mono break-all">{{ model.providerId }}</code>
          <p class="text-xs txt-secondary mt-1">
            {{ $t('adminModelStatus.retired.retiredOn', { date: formatDate(model.retiredOn) }) }}
            ·
            {{
              model.successorName
                ? $t('adminModelStatus.retired.successor', { name: model.successorName })
                : $t('adminModelStatus.retired.noSuccessor')
            }}
          </p>
        </li>
      </ul>
    </AccordionSection>
  </AccordionStack>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AccordionSection from '@/components/AccordionSection.vue'
import AccordionStack from '@/components/AccordionStack.vue'
import type { ModelStatusRetiredEntry } from '@/services/api/adminModelStatusApi'

defineProps<{
  models: ModelStatusRetiredEntry[]
}>()

const { t, locale } = useI18n()

const open = ref(false)

const capabilityLabel = (tag: string): string => {
  const key = `config.aiModels.capabilities.${tag}`
  const label = t(key)
  return label === key ? tag : label
}

/** `retiredOn` is a calendar date; read it as UTC so no timezone shifts the day. */
const formatDate = (isoDate: string): string => {
  const date = new Date(`${isoDate}T00:00:00Z`)
  if (Number.isNaN(date.getTime())) return isoDate
  return date.toLocaleDateString(locale.value, {
    timeZone: 'UTC',
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}
</script>
