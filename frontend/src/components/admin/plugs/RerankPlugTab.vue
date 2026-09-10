<template>
  <div data-testid="rerank-plug-tab">
    <p class="text-sm txt-secondary mb-4">{{ $t('aiInfra.rerank.lead') }}</p>

    <div v-if="loading" class="text-center py-12">
      <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
    </div>

    <div v-else-if="loadFailed" class="surface-card rounded-lg p-8 text-center">
      <p class="txt-secondary">{{ $t('aiInfra.rerank.loadFailed') }}</p>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium mt-4"
        @click="load"
      >
        {{ $t('common.retry') }}
      </button>
    </div>

    <template v-else>
      <div class="flex flex-wrap gap-2 mb-4">
        <span
          v-for="adapter in adapters"
          :key="adapter.key"
          class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs surface-card border border-light-border/30 dark:border-dark-border/20"
          :data-testid="`rerank-health-${adapter.key}`"
        >
          <span
            class="w-2 h-2 rounded-full"
            :class="
              adapter.health.available ? 'bg-[var(--status-success)]' : 'bg-[var(--status-warning)]'
            "
          />
          <span class="txt-primary">{{ adapter.label }}</span>
          <span class="txt-secondary">{{ healthLabel(adapter.health) }}</span>
        </span>
      </div>

      <div class="surface-card rounded-lg p-4 mb-4">
        <label class="flex items-start gap-2 text-sm txt-primary">
          <input v-model="enabled" type="checkbox" class="mt-1" data-testid="rerank-enabled" />
          <span>{{ $t('aiInfra.rerank.enable') }}</span>
        </label>

        <label class="block text-sm font-medium txt-primary mt-4" for="rerank-model">
          {{ $t('aiInfra.rerank.model') }}
        </label>
        <select
          id="rerank-model"
          v-model="modelKey"
          class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="rerank-model"
        >
          <option value="">{{ $t('aiInfra.rerank.modelNone') }}</option>
          <option
            v-for="model in models"
            :key="model.key"
            :value="model.key"
            :disabled="!model.available"
          >
            {{ model.label
            }}{{
              model.available ? '' : ` — ${model.reason || $t('aiInfra.rerank.healthUnavailable')}`
            }}
          </option>
        </select>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
          <div>
            <label class="block text-sm font-medium txt-primary" for="rerank-multiplier">
              {{ $t('aiInfra.rerank.multiplier') }}
            </label>
            <input
              id="rerank-multiplier"
              v-model.number="multiplier"
              type="number"
              min="2"
              max="10"
              class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="rerank-multiplier"
            />
          </div>
          <div>
            <label class="block text-sm font-medium txt-primary" for="rerank-budget">
              {{ $t('aiInfra.rerank.budgetMs') }}
            </label>
            <input
              id="rerank-budget"
              v-model.number="budgetMs"
              type="number"
              min="100"
              max="5000"
              class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="rerank-budget"
            />
          </div>
        </div>

        <label class="mt-4 flex items-start gap-2 text-sm txt-primary">
          <input
            v-model="llmFallback"
            type="checkbox"
            class="mt-1"
            data-testid="rerank-llm-fallback"
          />
          <span>
            {{ $t('aiInfra.rerank.llmFallback') }}
            <span class="block txt-secondary">{{ $t('aiInfra.rerank.llmFallbackHint') }}</span>
          </span>
        </label>

        <div v-for="provider in keyProviders" :key="provider" class="mt-4">
          <label class="block text-sm font-medium txt-primary" :for="`rerank-key-${provider}`">
            {{ $t(`aiInfra.rerank.apiKey.${provider}`) }}
          </label>
          <div class="mt-1 flex flex-col sm:flex-row gap-2">
            <input
              :id="`rerank-key-${provider}`"
              v-model="keyDraft[provider]"
              type="password"
              autocomplete="off"
              class="flex-1 min-w-0 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              :placeholder="keys[provider]?.maskedKey || $t('aiInfra.rerank.apiKeyPlaceholder')"
              :data-testid="`rerank-key-${provider}`"
            />
            <button
              type="button"
              class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
              :disabled="savingKey === provider || !keyDraft[provider]"
              :data-testid="`rerank-save-key-${provider}`"
              @click="saveKey(provider)"
            >
              {{ $t('aiInfra.rerank.saveKey') }}
            </button>
          </div>
        </div>

        <button
          type="button"
          class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium mt-4"
          :disabled="saving"
          data-testid="rerank-save"
          @click="save"
        >
          {{ saving ? $t('common.saving') : $t('aiInfra.rerank.save') }}
        </button>
      </div>

      <div v-if="lastEval" class="surface-card rounded-lg p-4 mb-4" data-testid="rerank-last-eval">
        <h3 class="text-sm font-semibold txt-primary">{{ $t('aiInfra.rerank.lastEval.title') }}</h3>
        <p class="text-sm txt-secondary mt-1">
          {{ $t('aiInfra.rerank.lastEval.line', lastEval) }}
        </p>
      </div>

      <div class="surface-card rounded-lg p-4">
        <h3 class="text-sm font-semibold txt-primary">{{ $t('aiInfra.rerank.testTitle') }}</h3>
        <p class="text-sm txt-secondary mt-1 mb-3">{{ $t('aiInfra.rerank.testHint') }}</p>
        <input
          v-model="testQuery"
          type="text"
          class="w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :placeholder="$t('aiInfra.rerank.testQueryPlaceholder')"
          data-testid="rerank-test-query"
        />
        <textarea
          v-model="testDocuments"
          rows="5"
          class="mt-2 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :placeholder="$t('aiInfra.rerank.testDocumentsPlaceholder')"
          data-testid="rerank-test-documents"
        />
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium mt-2"
          :disabled="testing || !testQuery.trim() || !testDocuments.trim()"
          data-testid="rerank-test-button"
          @click="runTest"
        >
          {{ testing ? $t('aiInfra.rerank.testing') : $t('aiInfra.rerank.testButton') }}
        </button>
        <div v-if="testResult" class="mt-3" data-testid="rerank-test-results">
          <p v-if="testResult.error" class="text-sm text-red-600 dark:text-red-400">
            {{ testResult.error }}
          </p>
          <ol v-else class="space-y-1 list-decimal list-inside">
            <li
              v-for="(hit, index) in testResult.ordered"
              :key="`${hit.index}-${index}`"
              class="text-sm txt-primary"
            >
              #{{ hit.index + 1 }} · {{ hit.score.toFixed(2) }}
            </li>
          </ol>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useNotification } from '@/composables/useNotification'
import { rerankSaveFeedback } from '@/components/admin/plugs/rerankSaveFeedback'
import {
  getRerankStatus,
  savePlugKey,
  saveRerank,
  testRerank,
  type RerankStatus,
  type RerankTestResult,
} from '@/services/api/adminPlugsApi'

const keyProviders = ['jina', 'cohere', 'voyage'] as const

const { t } = useI18n()
const { success, error: showError, warning, info } = useNotification()

const loading = ref(true)
const loadFailed = ref(false)
const saving = ref(false)
const testing = ref(false)
const savingKey = ref('')
const enabled = ref(false)
const modelKey = ref('')
const multiplier = ref(4)
const budgetMs = ref(800)
const llmFallback = ref(false)
const adapters = ref<RerankStatus['adapters']>([])
const models = ref<RerankStatus['models']>([])
const keys = ref<RerankStatus['keys']>({
  jina: { configured: false, source: 'none', origin: null, maskedKey: '' },
  cohere: { configured: false, source: 'none', origin: null, maskedKey: '' },
  voyage: { configured: false, source: 'none', origin: null, maskedKey: '' },
})
const lastEval = ref<RerankStatus['lastEval']>(null)
const testQuery = ref('')
const testDocuments = ref('')
const testResult = ref<RerankTestResult | null>(null)
const keyDraft = reactive<Record<string, string>>({})

onMounted(() => {
  void load()
})

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = false
  try {
    applyStatus(await getRerankStatus())
  } catch (err) {
    loadFailed.value = true
    showError(err instanceof Error ? err.message : t('aiInfra.rerank.loadFailed'))
  } finally {
    loading.value = false
  }
}

function applyStatus(status: RerankStatus): void {
  enabled.value = status.enabled
  modelKey.value = status.modelKey ?? ''
  multiplier.value = status.multiplier
  budgetMs.value = status.budgetMs
  llmFallback.value = status.llmFallback
  adapters.value = status.adapters
  models.value = status.models
  keys.value = status.keys
  lastEval.value = status.lastEval
}

async function save(): Promise<void> {
  saving.value = true
  try {
    const status = await saveRerank({
      enabled: enabled.value,
      modelKey: modelKey.value || null,
      multiplier: multiplier.value,
      budgetMs: budgetMs.value,
      llmFallback: llmFallback.value,
    })
    applyStatus(status)
    const feedback = rerankSaveFeedback(status)
    if (feedback === 'off') {
      info(t('aiInfra.rerank.savedOff'))
    } else if (feedback === 'inactive') {
      warning(t('aiInfra.rerank.savedInactive'))
    } else {
      success(t('aiInfra.rerank.saved'))
    }
  } catch (err) {
    showError(err instanceof Error ? err.message : t('aiInfra.rerank.saveFailed'))
  } finally {
    saving.value = false
  }
}

async function saveKey(provider: string): Promise<void> {
  const key = keyDraft[provider]?.trim()
  if (!key) return
  savingKey.value = provider
  try {
    await savePlugKey(provider, key)
    keyDraft[provider] = ''
    applyStatus(await getRerankStatus())
    success(t('aiInfra.rerank.keySaved'))
  } catch (err) {
    showError(err instanceof Error ? err.message : t('aiInfra.rerank.saveFailed'))
  } finally {
    savingKey.value = ''
  }
}

function healthLabel(health: { available: boolean; reason: string | null }): string {
  const status = health.available
    ? t('aiInfra.rerank.healthAvailable')
    : t('aiInfra.rerank.healthUnavailable')
  return health.reason ? `${status} — ${health.reason}` : status
}

async function runTest(): Promise<void> {
  testing.value = true
  testResult.value = null
  try {
    const documents = testDocuments.value
      .split('\n')
      .map((line) => line.trim())
      .filter((line) => line.length > 0)
    testResult.value = await testRerank(testQuery.value.trim(), documents, modelKey.value || null)
  } catch (err) {
    showError(err instanceof Error ? err.message : t('aiInfra.rerank.loadFailed'))
  } finally {
    testing.value = false
  }
}
</script>
