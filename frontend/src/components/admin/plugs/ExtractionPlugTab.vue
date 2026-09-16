<template>
  <div data-testid="extraction-plug-tab">
    <p class="text-sm txt-secondary mb-4">{{ $t('aiInfra.extraction.lead') }}</p>

    <div v-if="loading" class="text-center py-12">
      <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
    </div>

    <div v-else-if="loadFailed" class="surface-card rounded-lg p-8 text-center">
      <p class="txt-secondary">{{ $t('aiInfra.extraction.loadFailed') }}</p>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium mt-4"
        @click="load"
      >
        {{ $t('common.retry') }}
      </button>
    </div>

    <template v-else>
      <ExtractionSidecarPanel :adapters="adapters" />

      <div v-for="family in families" :key="family" class="surface-card rounded-lg p-4 mb-4">
        <h3 class="text-sm font-semibold txt-primary mb-3">
          {{ $t(`aiInfra.extraction.family.${family}`) }}
        </h3>
        <ol class="space-y-2">
          <li
            v-for="(key, index) in chains[family] ?? []"
            :key="`${family}-${key}-${index}`"
            class="flex items-center gap-2"
          >
            <span class="flex-1 min-w-0 text-sm txt-primary">{{ labelFor(key) }}</span>
            <button
              type="button"
              class="btn-secondary px-3 py-1.5 rounded-lg text-xs font-medium"
              :disabled="index === 0"
              :aria-label="$t('aiInfra.extraction.moveUp')"
              @click="move(family, index, -1)"
            >
              {{ $t('aiInfra.extraction.moveUp') }}
            </button>
            <button
              type="button"
              class="btn-secondary px-3 py-1.5 rounded-lg text-xs font-medium"
              :disabled="index === (chains[family]?.length ?? 0) - 1"
              :aria-label="$t('aiInfra.extraction.moveDown')"
              @click="move(family, index, 1)"
            >
              {{ $t('aiInfra.extraction.moveDown') }}
            </button>
            <button
              type="button"
              class="btn-danger px-3 py-1.5 rounded-lg text-xs font-medium"
              @click="removeKey(family, index)"
            >
              {{ $t('aiInfra.extraction.remove') }}
            </button>
          </li>
        </ol>
        <div class="mt-3 flex flex-col sm:flex-row gap-2">
          <select
            v-model="addKey[family]"
            class="flex-1 min-w-0 px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          >
            <option value="">{{ $t('aiInfra.extraction.addPlaceholder') }}</option>
            <option v-for="option in unusedKeys(family)" :key="option" :value="option">
              {{ labelFor(option) }}
            </option>
          </select>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
            :disabled="!addKey[family]"
            @click="add(family)"
          >
            {{ $t('aiInfra.extraction.add') }}
          </button>
        </div>
      </div>

      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium mb-8"
        :disabled="saving"
        data-testid="extraction-save-chains"
        @click="save"
      >
        {{ saving ? $t('common.saving') : $t('aiInfra.extraction.save') }}
      </button>

      <div class="surface-card rounded-lg p-4">
        <h3 class="text-sm font-semibold txt-primary">{{ $t('aiInfra.extraction.testTitle') }}</h3>
        <p class="text-sm txt-secondary mt-1 mb-3">{{ $t('aiInfra.extraction.testHint') }}</p>
        <input
          class="block w-full text-sm txt-primary mb-3"
          type="file"
          data-testid="extraction-test-file"
          @change="onFile"
        />
        <button
          type="button"
          class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
          :disabled="!testFile || testing"
          data-testid="extraction-test-button"
          @click="runTest"
        >
          {{ testing ? $t('aiInfra.extraction.testing') : $t('aiInfra.extraction.testButton') }}
        </button>
        <p
          v-if="testSummary"
          class="text-sm txt-primary mt-3"
          data-testid="extraction-test-summary"
        >
          {{ testSummary }}
        </p>
        <ul v-if="testResult" class="mt-3 space-y-1 text-sm txt-secondary">
          <li v-for="attempt in testResult.attempts" :key="`${attempt.key}-${attempt.ms}`">
            {{ attempt.key }} — {{ verdictLabel(attempt.verdict) }} ({{
              $t('aiInfra.extraction.attemptMs', { ms: attempt.ms })
            }})
          </li>
        </ul>
        <pre
          v-if="testResult?.preview"
          class="mt-3 p-3 rounded-lg text-xs txt-primary overflow-x-auto surface-card border border-light-border/30 dark:border-dark-border/20 whitespace-pre-wrap"
          >{{ testResult.preview }}</pre>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import ExtractionSidecarPanel from '@/components/admin/plugs/ExtractionSidecarPanel.vue'
import {
  getExtractionStatus,
  saveExtractionChains,
  testExtraction,
  type ExtractionStatus,
  type ExtractionTestResult,
} from '@/services/api/adminPlugsApi'

const { t } = useI18n()
const { success, error: showError } = useNotification()

const families = ['document', 'text', 'image', 'audio', 'audio_no_cloud', 'video'] as const

const loading = ref(true)
const loadFailed = ref(false)
const saving = ref(false)
const testing = ref(false)
const adapters = ref<ExtractionStatus['adapters']>([])
const chains = ref<Record<string, string[]>>({})
const addKey = ref<Record<string, string>>({})
const testFile = ref<File | null>(null)
const testResult = ref<ExtractionTestResult | null>(null)

const testSummary = computed(() => {
  const result = testResult.value
  if (!result) return ''
  const docling = result.attempts.find((attempt) => attempt.key === 'docling')
  if (docling && docling.verdict === 'rejected' && result.strategy === 'tika') {
    return t('aiInfra.extraction.doclingRejectedTika')
  }
  if (
    docling &&
    ['unavailable', 'empty', 'low_quality'].includes(docling.verdict) &&
    result.strategy === 'tika'
  ) {
    return t('aiInfra.extraction.doclingUnavailableTika')
  }
  return t('aiInfra.extraction.winner', { winner: result.winner ?? result.strategy })
})

function labelFor(key: string): string {
  return adapters.value.find((adapter) => adapter.key === key)?.label ?? key
}

function unusedKeys(family: string): string[] {
  const used = new Set(chains.value[family] ?? [])
  return adapters.value.map((adapter) => adapter.key).filter((key) => !used.has(key))
}

function move(family: string, index: number, delta: number) {
  const list = [...(chains.value[family] ?? [])]
  const next = index + delta
  if (next < 0 || next >= list.length) return
  const swap = list[index]
  list[index] = list[next]
  list[next] = swap
  chains.value = { ...chains.value, [family]: list }
}

function removeKey(family: string, index: number) {
  const list = [...(chains.value[family] ?? [])]
  list.splice(index, 1)
  chains.value = { ...chains.value, [family]: list }
}

function add(family: string) {
  const key = addKey.value[family]
  if (!key) return
  chains.value = { ...chains.value, [family]: [...(chains.value[family] ?? []), key] }
  addKey.value = { ...addKey.value, [family]: '' }
}

function verdictLabel(verdict: string): string {
  const key = `aiInfra.extraction.verdict.${verdict}`
  const translated = t(key)
  return translated === key ? verdict : translated
}

function onFile(event: Event) {
  const input = event.target as HTMLInputElement
  testFile.value = input.files?.[0] ?? null
}

async function load() {
  loading.value = true
  try {
    const status = await getExtractionStatus()
    adapters.value = status.adapters
    chains.value = { ...status.chains }
    loadFailed.value = false
  } catch (err) {
    loadFailed.value = true
    showError(err instanceof Error ? err.message : t('aiInfra.extraction.loadFailed'))
  } finally {
    loading.value = false
  }
}

async function save() {
  if (families.some((family) => (chains.value[family] ?? []).length === 0)) {
    showError(t('aiInfra.extraction.emptyChain'))
    return
  }
  saving.value = true
  try {
    const status = await saveExtractionChains(chains.value)
    adapters.value = status.adapters
    chains.value = { ...status.chains }
    success(t('aiInfra.extraction.saved'))
  } catch (err) {
    showError(err instanceof Error ? err.message : t('aiInfra.extraction.saveFailed'))
  } finally {
    saving.value = false
  }
}

async function runTest() {
  if (!testFile.value) return
  testing.value = true
  try {
    testResult.value = await testExtraction(testFile.value)
  } catch (err) {
    showError(err instanceof Error ? err.message : t('aiInfra.extraction.loadFailed'))
  } finally {
    testing.value = false
  }
}

onMounted(load)
</script>
