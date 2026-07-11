<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useUsageTaximeterStore } from '@/stores/usageTaximeter'
import { formatCostDisplay } from '@/utils/usageFormat'

/**
 * Shared session-statistics popover content for the taximeter (§1.3):
 * used models (active first, each with its session € and epoch-tone dot),
 * prompts in this session, model changes (only when > 0), and a link to the
 * full statistics page. Presentational — reads the store, emits nothing but a
 * navigate event so the parent can close the popover.
 */
const emit = defineEmits<{ (e: 'navigate'): void }>()

const store = useUsageTaximeterStore()
const { t, locale } = useI18n()

const lessThanCent = computed(() => t('usageTaximeter.lessThanCent'))
const cost = (value: number) => formatCostDisplay(value, locale.value, lessThanCent.value)
</script>

<template>
  <div class="usage-panel surface-card" role="dialog" :aria-label="t('usageTaximeter.openStats')">
    <div class="usage-panel__section">
      <div class="usage-panel__title txt-secondary">{{ t('usageTaximeter.usedModels') }}</div>
      <ul class="usage-panel__models">
        <li v-for="model in store.sortedModels" :key="model.modelKey" class="usage-panel__model">
          <span
            class="usage-panel__dot"
            :class="model.tone === 'b' ? 'usage-panel__dot--b' : 'usage-panel__dot--a'"
            aria-hidden="true"
          />
          <span class="usage-panel__model-label txt-primary" :title="model.modelKey">
            {{ model.label }}
          </span>
          <span class="usage-panel__model-cost txt-secondary">{{ cost(model.cost) }}</span>
        </li>
      </ul>
    </div>

    <div class="usage-panel__row txt-secondary">
      <span>{{ t('usageTaximeter.promptsInSession') }}</span>
      <span class="usage-panel__num txt-primary">{{ store.promptCount }}</span>
    </div>

    <div v-if="store.modelChanges > 0" class="usage-panel__row txt-secondary">
      <span>{{ t('usageTaximeter.modelChanges', { count: store.modelChanges }) }}</span>
    </div>

    <RouterLink to="/statistics" class="usage-panel__link txt-brand" @click="emit('navigate')">
      <Icon icon="mdi:chart-line" class="usage-panel__link-icon" aria-hidden="true" />
      {{ t('usageTaximeter.allStatistics') }}
    </RouterLink>
  </div>
</template>

<style scoped>
.usage-panel {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 0.875rem 1rem;
  width: 15rem;
  max-width: min(80vw, 18rem);
  font-size: 0.8125rem;
}

.usage-panel__title {
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  margin-bottom: 0.5rem;
}

.usage-panel__models {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.usage-panel__model {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.usage-panel__dot {
  width: 0.625rem;
  height: 0.625rem;
  border-radius: 9999px;
  flex-shrink: 0;
}
.usage-panel__dot--a {
  background: var(--usage-epoch-a);
}
.usage-panel__dot--b {
  background: var(--usage-epoch-b);
}

.usage-panel__model-label {
  flex: 1 1 auto;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.usage-panel__model-cost {
  font-variant-numeric: tabular-nums;
  flex-shrink: 0;
}

.usage-panel__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.usage-panel__num {
  font-variant-numeric: tabular-nums;
}

.usage-panel__link {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  font-weight: 600;
  text-decoration: none;
}
.usage-panel__link:hover {
  text-decoration: underline;
}

.usage-panel__link-icon {
  width: 1rem;
  height: 1rem;
}
</style>
