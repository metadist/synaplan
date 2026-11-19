import type { useI18n } from 'vue-i18n'
import type { ResearchAdmin } from '@/services/api/researchAdminApi'
import { REFUSAL_KEYS, researchRefusal } from './researchAdminErrors'

export type ResearchTranslate = ReturnType<typeof useI18n>['t']
export type ModelField = 'readingModelId' | 'checkingModelId'
export type BudgetKey = keyof ResearchAdmin['budgets']['quick']
export type LimitKey = keyof ResearchAdmin['limits']

export const FIELD_CLASS =
  'mt-1 w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]'
export const DEPTHS = ['quick', 'standard', 'thorough'] as const
export type Depth = (typeof DEPTHS)[number]

export const BUDGET_FIELDS: {
  key: BudgetKey
  label: string
  min: number
  max: number
  step: number
}[] = [
  { key: 'searches', label: 'adminSetup.research.searches', min: 0, max: 200, step: 1 },
  { key: 'sources', label: 'adminSetup.research.sources', min: 1, max: 200, step: 1 },
  { key: 'minutes', label: 'adminSetup.research.minutes', min: 1, max: 120, step: 1 },
  { key: 'costCap', label: 'adminSetup.research.costCap', min: 0, max: 50, step: 0.01 },
]
export const LIMIT_FIELDS: { key: LimitKey; label: string; min: number; max: number }[] = [
  { key: 'maxActiveRuns', label: 'adminSetup.research.maxActive', min: 1, max: 100 },
  { key: 'maxActiveThorough', label: 'adminSetup.research.maxThorough', min: 0, max: 10 },
]
export const modelSlots: { field: ModelField; label: string; testid: string }[] = [
  {
    field: 'readingModelId',
    label: 'adminSetup.research.readingLabel',
    testid: 'research-admin-reading',
  },
  {
    field: 'checkingModelId',
    label: 'adminSetup.research.checkingLabel',
    testid: 'research-admin-checking',
  },
]

export function modelSelection(stored: ResearchAdmin | null): Record<ModelField, string> {
  return {
    readingModelId: stored?.readingModelId ? String(stored.readingModelId) : '',
    checkingModelId: stored?.checkingModelId ? String(stored.checkingModelId) : '',
  }
}

export function editable(view: ResearchAdmin | null, picked: Record<ModelField, string>): string {
  if (!view) return ''
  const budgets = DEPTHS.map((depth) =>
    BUDGET_FIELDS.map((field) => view.budgets[depth][field.key])
  )
  const limits = LIMIT_FIELDS.map((field) => view.limits[field.key])
  return JSON.stringify([budgets, limits, picked])
}

export function outOfRange(value: unknown, min: number, max: number, whole: boolean): boolean {
  return (
    typeof value !== 'number' ||
    !Number.isFinite(value) ||
    value < min ||
    value > max ||
    (whole && !Number.isInteger(value))
  )
}

export function researchFieldLabel(t: ResearchTranslate, field: string | null): string {
  const [section, depth, key] = (field ?? '').split('.')
  if (section === 'budgets' && depth && key) {
    return `${t(`adminSetup.research.depth.${depth}`)} · ${t(`adminSetup.research.${key}`)}`
  }
  const limit = LIMIT_FIELDS.find((item) => `limits.${item.key}` === field)
  return limit ? t(limit.label) : ''
}

export function researchModelLabel(
  t: ResearchTranslate,
  model: ResearchAdmin['models'][number]
): string {
  if (model.available) return model.name
  const reason =
    model.reason === 'not_pulled'
      ? t('adminSetup.research.reasonNotPulled')
      : t('adminSetup.research.reasonUnavailable')
  return `${model.name} — ${reason}`
}

export function researchRefusalText(
  t: ResearchTranslate,
  error: unknown,
  fallback: string
): string {
  const refusal = researchRefusal(error)
  if (refusal?.code === 'out_of_range') {
    return t('adminSetup.research.errors.outOfRange', {
      field: researchFieldLabel(t, refusal.field),
    })
  }
  const key = refusal ? REFUSAL_KEYS[refusal.code] : undefined
  return key ? t(key) : t(fallback)
}

export function firstOutOfRange(view: ResearchAdmin): string | null {
  for (const depth of DEPTHS) {
    for (const field of BUDGET_FIELDS) {
      if (outOfRange(view.budgets[depth][field.key], field.min, field.max, field.step === 1)) {
        return `budgets.${depth}.${field.key}`
      }
    }
  }
  const limit = LIMIT_FIELDS.find((item) =>
    outOfRange(view.limits[item.key], item.min, item.max, true)
  )
  return limit ? `limits.${limit.key}` : null
}
