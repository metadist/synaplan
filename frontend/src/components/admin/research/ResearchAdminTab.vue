<template>
  <div data-testid="research-admin-tab">
    <div v-if="loading" class="text-center py-12">
      <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
    </div>

    <div v-else-if="loadFailed" class="surface-card rounded-lg p-8 text-center">
      <p class="txt-secondary">{{ t('adminSetup.research.loadFailed') }}</p>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 rounded-xl text-sm font-medium mt-4"
        @click="load"
      >
        {{ t('common.retry') }}
      </button>
    </div>

    <template v-else-if="form">
      <section class="surface-card rounded-lg p-4 mb-6" data-testid="research-admin-status">
        <h2 class="text-lg font-semibold txt-primary">
          {{ form.enabled ? t('adminSetup.research.onTitle') : t('adminSetup.research.offTitle') }}
        </h2>
        <p class="text-sm txt-secondary mt-1">
          {{ form.enabled ? t('adminSetup.research.onBody') : t('adminSetup.research.offBody') }}
        </p>
        <p v-if="form.envLocked" class="text-sm txt-secondary mt-3">
          {{ t('adminSetup.research.envLocked') }}
        </p>
        <div v-else class="mt-4 flex flex-wrap gap-3">
          <button
            v-if="!form.enabled"
            type="button"
            class="btn-primary px-4 py-2.5 rounded-xl text-sm font-medium"
            data-testid="research-admin-turn-on"
            @click="setEnabled(true)"
          >
            {{ t('adminSetup.research.turnOn') }}
          </button>
          <div v-else class="space-y-3">
            <p class="text-sm txt-secondary">{{ t('adminSetup.research.turnOffHint') }}</p>
            <button
              type="button"
              class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium"
              data-testid="research-admin-turn-off"
              @click="setEnabled(false)"
            >
              {{ t('adminSetup.research.turnOff') }}
            </button>
          </div>
        </div>
      </section>

      <section class="surface-card rounded-lg p-4 mb-6">
        <h2 class="text-lg font-semibold txt-primary">{{ t('adminSetup.research.teamTitle') }}</h2>
        <p class="text-sm txt-secondary mt-1">{{ t('adminSetup.research.planningNote') }}</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <label class="block text-sm">
            <span class="font-medium txt-primary">{{ t('adminSetup.research.readingLabel') }}</span>
            <select
              v-model="reading"
              class="mt-1 w-full rounded-lg border px-3 py-2 bg-transparent"
              data-testid="research-admin-reading"
            >
              <option value="">{{ t('adminSetup.research.recommended') }}</option>
              <option
                v-for="model in form.models"
                :key="model.id"
                :value="String(model.id)"
                :disabled="!model.available"
              >
                {{ modelLabel(model) }}
              </option>
            </select>
          </label>
          <label class="block text-sm">
            <span class="font-medium txt-primary">{{
              t('adminSetup.research.checkingLabel')
            }}</span>
            <select
              v-model="checking"
              class="mt-1 w-full rounded-lg border px-3 py-2 bg-transparent"
              data-testid="research-admin-checking"
            >
              <option value="">{{ t('adminSetup.research.recommended') }}</option>
              <option
                v-for="model in form.models"
                :key="`c-${model.id}`"
                :value="String(model.id)"
                :disabled="!model.available"
              >
                {{ modelLabel(model) }}
              </option>
            </select>
          </label>
        </div>
      </section>

      <section class="surface-card rounded-lg p-4 mb-6">
        <h2 class="text-lg font-semibold txt-primary">
          {{ t('adminSetup.research.budgetsTitle') }}
        </h2>
        <div v-for="depth in depths" :key="depth" class="mt-4">
          <h3 class="text-sm font-semibold txt-primary">
            {{ t(`adminSetup.research.depth.${depth}`) }}
          </h3>
          <div class="mt-2 grid gap-3 sm:grid-cols-4">
            <label class="text-sm">
              {{ t('adminSetup.research.searches') }}
              <input
                v-model.number="form.budgets[depth].searches"
                class="mt-1 w-full rounded-lg border px-3 py-2 bg-transparent"
                type="number"
                min="0"
                max="200"
              />
            </label>
            <label class="text-sm">
              {{ t('adminSetup.research.sources') }}
              <input
                v-model.number="form.budgets[depth].sources"
                class="mt-1 w-full rounded-lg border px-3 py-2 bg-transparent"
                type="number"
                min="1"
                max="200"
              />
            </label>
            <label class="text-sm">
              {{ t('adminSetup.research.minutes') }}
              <input
                v-model.number="form.budgets[depth].minutes"
                class="mt-1 w-full rounded-lg border px-3 py-2 bg-transparent"
                type="number"
                min="1"
                max="120"
              />
            </label>
            <label class="text-sm">
              {{ t('adminSetup.research.costCap') }}
              <input
                v-model.number="form.budgets[depth].costCap"
                class="mt-1 w-full rounded-lg border px-3 py-2 bg-transparent"
                type="number"
                min="0"
                max="50"
                step="0.01"
              />
            </label>
          </div>
        </div>
      </section>

      <section class="surface-card rounded-lg p-4 mb-6">
        <h2 class="text-lg font-semibold txt-primary">
          {{ t('adminSetup.research.limitsTitle') }}
        </h2>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <label class="text-sm">
            {{ t('adminSetup.research.maxActive') }}
            <input
              v-model.number="form.limits.maxActiveRuns"
              class="mt-1 w-full rounded-lg border px-3 py-2 bg-transparent"
              type="number"
              min="1"
              max="100"
            />
          </label>
          <label class="text-sm">
            {{ t('adminSetup.research.maxThorough') }}
            <input
              v-model.number="form.limits.maxActiveThorough"
              class="mt-1 w-full rounded-lg border px-3 py-2 bg-transparent"
              type="number"
              min="0"
              max="10"
            />
          </label>
        </div>
        <button
          type="button"
          class="btn-primary px-4 py-2.5 rounded-xl text-sm font-medium mt-4"
          data-testid="research-admin-save"
          :disabled="saving"
          @click="save"
        >
          {{ t('adminSetup.research.save') }}
        </button>
        <p v-if="saved" class="text-sm txt-secondary mt-2">{{ t('adminSetup.research.saved') }}</p>
        <p v-if="saveError" class="text-sm txt-secondary mt-2" role="alert">{{ saveError }}</p>
      </section>

      <section class="surface-card rounded-lg p-4">
        <h2 class="text-lg font-semibold txt-primary">
          {{ t('adminSetup.research.sampleTitle') }}
        </h2>
        <p class="text-sm txt-secondary mt-1">{{ t('adminSetup.research.sampleHint') }}</p>
        <p
          v-if="sampleSentence"
          class="text-sm txt-primary mt-3"
          data-testid="research-admin-sample-result"
        >
          {{ sampleSentence }}
        </p>
        <label class="mt-4 flex items-start gap-2 text-sm txt-secondary">
          <input
            v-model="sampleReady"
            type="checkbox"
            data-testid="research-admin-sample-confirm"
          />
          <span>{{ t('adminSetup.research.sampleConfirm', { amount: storedQuickCap }) }}</span>
        </label>
        <button
          type="button"
          class="btn-secondary px-4 py-2.5 rounded-xl text-sm font-medium mt-3"
          data-testid="research-admin-sample"
          :disabled="!form.enabled || !sampleReady || sampling"
          @click="sample"
        >
          {{ t('adminSetup.research.sampleButton') }}
        </button>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import {
  fetchResearchAdmin,
  saveResearchAdmin,
  startResearchSample,
  type ResearchAdmin,
} from '@/services/api/researchAdminApi'
import { ApiError } from '@/services/api/httpClient'

const { t } = useI18n()
const depths = ['quick', 'standard', 'thorough'] as const
const loading = ref(true)
const loadFailed = ref(false)
const saving = ref(false)
const saved = ref(false)
const saveError = ref('')
const sampling = ref(false)
const sampleReady = ref(false)
const sampleSentence = ref('')
const form = ref<ResearchAdmin | null>(null)
const reading = ref('')
const checking = ref('')
const storedQuickCap = ref(0)

function errorSentence(error: unknown): string {
  const nested = error instanceof ApiError ? error.details?.error : undefined
  if (
    nested &&
    typeof nested === 'object' &&
    'message' in nested &&
    typeof nested.message === 'string'
  ) {
    return nested.message
  }
  return error instanceof Error ? error.message : t('adminSetup.research.saveFailed')
}

function modelLabel(model: ResearchAdmin['models'][number]): string {
  if (model.available) return model.name
  const reason =
    model.reason === 'not_pulled'
      ? t('adminSetup.research.reasonNotPulled')
      : t('adminSetup.research.reasonUnavailable')
  return `${model.name} — ${reason}`
}

function apply(next: ResearchAdmin): void {
  form.value = next
  storedQuickCap.value = next.budgets.quick.costCap
  reading.value = next.readingModelId ? String(next.readingModelId) : ''
  checking.value = next.checkingModelId ? String(next.checkingModelId) : ''
  sampleSentence.value = next.sample?.sentence ?? sampleSentence.value
}

async function load(): Promise<void> {
  loading.value = true
  loadFailed.value = false
  try {
    apply(await fetchResearchAdmin())
  } catch {
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

async function setEnabled(enabled: boolean): Promise<void> {
  saveError.value = ''
  try {
    apply(await saveResearchAdmin({ enabled }))
  } catch (error) {
    saveError.value = errorSentence(error)
  }
}

async function save(): Promise<void> {
  if (!form.value) return
  saving.value = true
  saved.value = false
  saveError.value = ''
  try {
    apply(
      await saveResearchAdmin({
        readingModelId: reading.value === '' ? 0 : Number(reading.value),
        checkingModelId: checking.value === '' ? 0 : Number(checking.value),
        limits: form.value.limits,
        budgets: form.value.budgets,
      })
    )
    saved.value = true
  } catch (error) {
    saveError.value = errorSentence(error)
  } finally {
    saving.value = false
  }
}

async function sample(): Promise<void> {
  sampling.value = true
  saveError.value = ''
  try {
    const started = await startResearchSample()
    sampleSentence.value = started.sentence
    sampleReady.value = false
  } catch (error) {
    saveError.value = errorSentence(error)
  } finally {
    sampling.value = false
  }
}

onMounted(() => {
  void load()
})
</script>
