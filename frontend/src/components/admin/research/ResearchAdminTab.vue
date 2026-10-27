<template>
  <div data-testid="research-admin-tab">
    <div v-if="loading" class="text-center py-12">
      <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
    </div>

    <div v-else-if="loadFailed" class="surface-card rounded-lg p-8 text-center">
      <p class="txt-secondary">{{ t('adminSetup.research.loadFailed') }}</p>
      <button type="button" class="btn-primary px-4 py-2.5 text-sm font-medium mt-4" @click="load">
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
        <div v-else class="mt-4 space-y-3">
          <p v-if="form.enabled" class="text-sm txt-secondary">
            {{ t('adminSetup.research.turnOffHint') }}
          </p>
          <button
            type="button"
            :class="form.enabled ? 'btn-secondary' : 'btn-primary'"
            class="px-4 py-2.5 text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
            :data-testid="form.enabled ? 'research-admin-turn-off' : 'research-admin-turn-on'"
            :disabled="switching"
            @click="setEnabled(!form.enabled)"
          >
            {{ form.enabled ? t('adminSetup.research.turnOff') : t('adminSetup.research.turnOn') }}
          </button>
        </div>
        <p v-if="errorPlace === 'switch'" class="text-sm text-red-600 dark:text-red-400 mt-3" role="alert">
          {{ errorText }}
        </p>
      </section>

      <section class="surface-card rounded-lg p-4 mb-6">
        <h2 class="text-lg font-semibold txt-primary">{{ t('adminSetup.research.teamTitle') }}</h2>
        <p class="text-sm txt-secondary mt-1">{{ t('adminSetup.research.planningNote') }}</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <label v-for="slot in modelSlots" :key="slot.field" class="block text-sm">
            <span class="font-medium txt-primary">{{ t(slot.label) }}</span>
            <select
              v-model="models[slot.field]"
              :class="FIELD_CLASS"
              :data-testid="slot.testid"
            >
              <option value="">{{ t('adminSetup.research.recommended') }}</option>
              <option
                v-for="model in form.models"
                :key="model.id"
                :value="String(model.id)"
                :disabled="!model.available && String(model.id) !== models[slot.field]"
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
        <div v-for="depth in DEPTHS" :key="depth" class="mt-4">
          <h3 class="text-sm font-semibold txt-primary">
            {{ t(`adminSetup.research.depth.${depth}`) }}
          </h3>
          <div class="mt-2 grid gap-3 grid-cols-2 sm:grid-cols-4">
            <label v-for="field in BUDGET_FIELDS" :key="field.key" class="block text-sm txt-primary">
              {{ t(field.label) }}
              <input
                v-model.number="form.budgets[depth][field.key]"
                :class="FIELD_CLASS"
                type="number"
                :min="field.min"
                :max="field.max"
                :step="field.step"
                :data-testid="`research-admin-${depth}-${field.key}`"
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
          <label v-for="field in LIMIT_FIELDS" :key="field.key" class="block text-sm txt-primary">
            {{ t(field.label) }}
            <input
              v-model.number="form.limits[field.key]"
              :class="FIELD_CLASS"
              type="number"
              :min="field.min"
              :max="field.max"
              step="1"
            />
          </label>
        </div>
        <button
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium mt-4 disabled:opacity-50 disabled:cursor-not-allowed"
          data-testid="research-admin-save"
          :disabled="saving || !dirty"
          @click="save"
        >
          {{ t('adminSetup.research.save') }}
        </button>
        <p v-if="saved && !dirty" class="text-sm txt-secondary mt-2" role="status">
          {{ t('adminSetup.research.saved') }}
        </p>
        <p v-if="errorPlace === 'save'" class="text-sm text-red-600 dark:text-red-400 mt-2" role="alert">
          {{ errorText }}
        </p>
      </section>

      <ResearchSampleCard
        :enabled="form.enabled"
        :dirty="dirty"
        :quick-cost-cap="stored?.budgets.quick.costCap ?? 0"
        :sample="form.sample"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import {
  fetchResearchAdmin,
  saveResearchAdmin,
  type ResearchAdmin,
} from '@/services/api/researchAdminApi'
import ResearchSampleCard from './ResearchSampleCard.vue'
import { REFUSAL_KEYS, researchRefusal } from './researchAdminErrors'

type ModelField = 'readingModelId' | 'checkingModelId'
type BudgetKey = keyof ResearchAdmin['budgets']['quick']
type LimitKey = keyof ResearchAdmin['limits']

const FIELD_CLASS =
  'mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]'
const DEPTHS = ['quick', 'standard', 'thorough'] as const
const BUDGET_FIELDS: { key: BudgetKey; label: string; min: number; max: number; step: number }[] = [
  { key: 'searches', label: 'adminSetup.research.searches', min: 0, max: 200, step: 1 },
  { key: 'sources', label: 'adminSetup.research.sources', min: 1, max: 200, step: 1 },
  { key: 'minutes', label: 'adminSetup.research.minutes', min: 1, max: 120, step: 1 },
  { key: 'costCap', label: 'adminSetup.research.costCap', min: 0, max: 50, step: 0.01 },
]
const LIMIT_FIELDS: { key: LimitKey; label: string; min: number; max: number }[] = [
  { key: 'maxActiveRuns', label: 'adminSetup.research.maxActive', min: 1, max: 100 },
  { key: 'maxActiveThorough', label: 'adminSetup.research.maxThorough', min: 0, max: 10 },
]
const modelSlots: { field: ModelField; label: string; testid: string }[] = [
  { field: 'readingModelId', label: 'adminSetup.research.readingLabel', testid: 'research-admin-reading' },
  { field: 'checkingModelId', label: 'adminSetup.research.checkingLabel', testid: 'research-admin-checking' },
]

const { t } = useI18n()
const loading = ref(true)
const loadFailed = ref(false)
const switching = ref(false)
const saving = ref(false)
const saved = ref(false)
const errorText = ref('')
const errorPlace = ref<'switch' | 'save' | ''>('')
const form = ref<ResearchAdmin | null>(null)
const stored = ref<ResearchAdmin | null>(null)
const models = ref<Record<ModelField, string>>({ readingModelId: '', checkingModelId: '' })

const editable = (view: ResearchAdmin | null, picked: Record<ModelField, string>) =>
  view ? JSON.stringify([view.budgets, view.limits, picked]) : ''
const storedModels = computed<Record<ModelField, string>>(() => ({
  readingModelId: stored.value?.readingModelId ? String(stored.value.readingModelId) : '',
  checkingModelId: stored.value?.checkingModelId ? String(stored.value.checkingModelId) : '',
}))
const dirty = computed(
  () => editable(form.value, models.value) !== editable(stored.value, storedModels.value)
)

function modelLabel(model: ResearchAdmin['models'][number]): string {
  if (model.available) return model.name
  const reason =
    model.reason === 'not_pulled'
      ? t('adminSetup.research.reasonNotPulled')
      : t('adminSetup.research.reasonUnavailable')
  return `${model.name} — ${reason}`
}

function fieldLabel(field: string | null): string {
  const [section, depth, key] = (field ?? '').split('.')
  if (section === 'budgets' && depth && key) {
    return `${t(`adminSetup.research.depth.${depth}`)} · ${t(`adminSetup.research.${key}`)}`
  }
  const limit = LIMIT_FIELDS.find((item) => `limits.${item.key}` === field)
  return limit ? t(limit.label) : ''
}

function refusalText(error: unknown, fallback: string): string {
  const refusal = researchRefusal(error)
  if (refusal?.code === 'out_of_range') {
    return t('adminSetup.research.errors.outOfRange', { field: fieldLabel(refusal.field) })
  }
  const key = refusal ? REFUSAL_KEYS[refusal.code] : undefined
  return key ? t(key) : t(fallback)
}

function firstOutOfRange(view: ResearchAdmin): string | null {
  const bad = (value: unknown, min: number, max: number) =>
    typeof value !== 'number' || !Number.isFinite(value) || value < min || value > max
  for (const depth of DEPTHS) {
    for (const field of BUDGET_FIELDS) {
      const value = view.budgets[depth][field.key]
      const whole = field.step === 1 && !Number.isInteger(value)
      if (bad(value, field.min, field.max) || whole) return `budgets.${depth}.${field.key}`
    }
  }
  const limit = LIMIT_FIELDS.find((item) => {
    const value = view.limits[item.key]
    return bad(value, item.min, item.max) || !Number.isInteger(value)
  })
  return limit ? `limits.${limit.key}` : null
}

function apply(next: ResearchAdmin): void {
  stored.value = next
  form.value = JSON.parse(JSON.stringify(next)) as ResearchAdmin
  models.value = { ...storedModels.value }
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
  if (!form.value || !stored.value) return
  switching.value = true
  errorPlace.value = ''
  try {
    const next = await saveResearchAdmin({ enabled })
    const keepEdits = dirty.value ? { budgets: form.value.budgets, limits: form.value.limits } : {}
    const picked = { ...models.value }
    apply(next)
    Object.assign(form.value, keepEdits)
    models.value = picked
  } catch (error) {
    errorPlace.value = 'switch'
    errorText.value = refusalText(error, 'adminSetup.research.errors.switchFailed')
  } finally {
    switching.value = false
  }
}

async function save(): Promise<void> {
  if (!form.value) return
  saved.value = false
  errorPlace.value = ''
  const invalid = firstOutOfRange(form.value)
  if (invalid) {
    errorPlace.value = 'save'
    errorText.value = t('adminSetup.research.errors.outOfRange', { field: fieldLabel(invalid) })
    return
  }
  const body: Record<string, unknown> = { limits: form.value.limits, budgets: form.value.budgets }
  for (const slot of modelSlots) {
    if (models.value[slot.field] !== storedModels.value[slot.field]) {
      body[slot.field] = models.value[slot.field] === '' ? 0 : Number(models.value[slot.field])
    }
  }
  saving.value = true
  try {
    apply(await saveResearchAdmin(body))
    saved.value = true
  } catch (error) {
    errorPlace.value = 'save'
    errorText.value = refusalText(error, 'adminSetup.research.errors.saveFailed')
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  void load()
})
</script>
