<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import SearchModelField from '@/components/admin/search/SearchModelField.vue'
import { useSearchModels } from '@/composables/search/useSearchModels'

const FEATURE_LINK = {
  path: '/admin/config',
  query: { tab: 'features', section: 'search', highlight: 'FEATURE_SEARCH_AI_ENABLED' },
}

const { t } = useI18n()
const { config, loading, loadFailed, saving, run, refresh, changeAi, changeEmbed, modelName } =
  useSearchModels()

const inheritLabel = (slot: 'ai' | 'embed') =>
  t(`aiInfra.searchModels.${slot}.inherit`, {
    model: modelName(slot, config.value?.[slot].inheritedModelId),
  })

const embedLocked = computed(
  () => config.value !== null && (config.value.activeRun !== null || config.value.otherRunActive)
)

const aiStatus = computed(() => {
  if (!config.value) return ''
  if (!config.value.aiEnabled) return t('aiInfra.searchModels.ai.off')
  if (!config.value.aiAvailable) return t('aiInfra.searchModels.ai.unavailable')
  return t('aiInfra.searchModels.ai.ready', {
    model: modelName('ai', config.value.ai.effectiveModelId),
  })
})

const separateModels = computed(
  () =>
    config.value !== null &&
    config.value.embed.selectedModelId !== null &&
    config.value.embed.selectedModelId !== config.value.embed.inheritedModelId
)

const indexStatus = computed(() => {
  const index = config.value?.index
  if (!index) return ''
  if (index.rows === 0) return t('aiInfra.searchModels.index.empty')
  if (!index.semanticAvailable) return t('aiInfra.searchModels.index.keywordOnly')
  return t('aiInfra.searchModels.index.coverage', {
    embedded: index.embeddedRows,
    rows: index.rows,
  })
})

const runActive = computed(() => run.value?.status === 'queued' || run.value?.status === 'running')
const runPercent = computed(() => {
  const total = run.value?.rowsTotal ?? 0
  if (!run.value || total === 0) return 0
  return Math.min(100, Math.round(((run.value.rowsProcessed + run.value.rowsFailed) / total) * 100))
})

const runStatus = computed(() => {
  const current = run.value
  if (!current) return ''
  const model = modelName('embed', current.toModelId)
  const previous = modelName('embed', current.fromModelId)
  switch (current.status) {
    case 'queued':
    case 'running':
      return t('aiInfra.searchModels.run.running', {
        model,
        done: current.rowsProcessed + current.rowsFailed,
        total: current.rowsTotal ?? 0,
      })
    case 'completed':
      return current.rowsFailed > 0
        ? t('aiInfra.searchModels.run.partial', { model, count: current.rowsFailed })
        : t('aiInfra.searchModels.run.completed', { model })
    case 'failed':
      return t('aiInfra.searchModels.run.failed', { model, previous })
    default:
      return t('aiInfra.searchModels.run.cancelled', { model, previous })
  }
})

onMounted(refresh)
</script>

<template>
  <section
    class="space-y-3"
    aria-labelledby="ai-search-models"
    data-testid="card-smart-search-models"
  >
    <div>
      <h2 id="ai-search-models" class="text-lg font-semibold txt-primary">
        {{ $t('aiInfra.searchModels.title') }}
      </h2>
      <p class="text-sm txt-secondary mt-1">{{ $t('aiInfra.searchModels.lead') }}</p>
    </div>

    <div v-if="loading" class="surface-card rounded-lg p-6 text-sm txt-secondary" role="status">
      {{ $t('aiInfra.searchModels.loading') }}
    </div>

    <div v-else-if="loadFailed || !config" class="surface-card rounded-lg p-6 text-center">
      <p class="text-sm txt-secondary">{{ $t('aiInfra.searchModels.loadFailed') }}</p>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium mt-4"
        data-testid="btn-smart-search-models-retry"
        @click="refresh"
      >
        {{ $t('common.retry') }}
      </button>
    </div>

    <div v-else class="surface-card rounded-lg p-4 space-y-5">
      <div class="space-y-2">
        <SearchModelField
          id="smart-search-ai-model"
          :label="$t('aiInfra.searchModels.ai.label')"
          :hint="$t('aiInfra.searchModels.ai.hint')"
          :inherit-label="inheritLabel('ai')"
          :slot-config="config.ai"
          :disabled="saving !== null"
          @change="changeAi"
        />
        <p class="text-sm txt-primary" role="status" data-testid="text-smart-search-ai-status">
          {{ aiStatus }}
          <RouterLink
            v-if="!config.aiEnabled"
            :to="FEATURE_LINK"
            class="underline text-[var(--brand)]"
            data-testid="link-smart-search-ai-flag"
          >
            {{ $t('aiInfra.searchModels.ai.openFlag') }}
          </RouterLink>
        </p>
      </div>

      <div class="space-y-2">
        <SearchModelField
          id="smart-search-embed-model"
          :label="$t('aiInfra.searchModels.embed.label')"
          :hint="$t('aiInfra.searchModels.embed.hint')"
          :inherit-label="inheritLabel('embed')"
          :slot-config="config.embed"
          :disabled="saving !== null || embedLocked"
          @change="changeEmbed"
        />
        <p
          v-if="config.otherRunActive"
          class="text-sm txt-secondary"
          data-testid="text-smart-search-other-run"
        >
          {{ $t('aiInfra.searchModels.embed.otherRun') }}
        </p>
        <p
          v-if="separateModels"
          class="text-sm txt-secondary"
          data-testid="text-smart-search-separate"
        >
          {{ $t('aiInfra.searchModels.embed.separate') }}
        </p>
      </div>

      <div class="space-y-2" data-testid="smart-search-index-status">
        <p class="text-sm txt-primary">{{ indexStatus }}</p>
        <div v-if="run" class="space-y-2" role="status" data-testid="smart-search-run">
          <div
            v-if="runActive"
            class="w-full h-2 rounded-full bg-black/10 dark:bg-white/10 overflow-hidden"
          >
            <div
              class="h-full rounded-full bg-[var(--brand)] transition-all duration-500"
              :style="{ width: `${runPercent}%` }"
            />
          </div>
          <p class="text-sm txt-secondary" data-testid="text-smart-search-run">{{ runStatus }}</p>
        </div>
      </div>
    </div>
  </section>
</template>
