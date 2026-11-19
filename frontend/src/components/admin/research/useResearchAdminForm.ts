/**
 * Research limits stay editable while they are typed. Each value is stored
 * when the field is left. The save row stays in the page: a bar that appears
 * over the fields jumps away again while a half-typed number still matches
 * what is stored.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import type { ResearchAdmin } from '@/services/api/researchAdminApi'
import {
  BUDGET_FIELDS,
  DEPTHS,
  FIELD_CLASS,
  LIMIT_FIELDS,
  editable,
  modelSelection,
  modelSlots,
  researchModelLabel,
  type BudgetKey,
  type Depth,
  type LimitKey,
  type ModelField,
} from './researchAdminFields'
import {
  adoptResearchSwitch,
  commitResearchBudget,
  commitResearchLimit,
  commitResearchModel,
  discardResearchEdits,
  leaveField,
  loadResearchAdmin,
  saveResearchForm,
  setResearchEnabled,
  type ResearchAdminContext,
} from './researchAdminSave'

function createResearchAdminContext(): ResearchAdminContext {
  const { t } = useI18n()
  const { success: notifySaved, error: notifyFailed } = useNotification()
  const form = ref<ResearchAdmin | null>(null)
  const stored = ref<ResearchAdmin | null>(null)
  const models = ref<Record<ModelField, string>>({ readingModelId: '', checkingModelId: '' })
  const storedModels = computed(() => modelSelection(stored.value))
  const dirty = computed(
    () => editable(form.value, models.value) !== editable(stored.value, storedModels.value)
  )
  return {
    t,
    notifySaved,
    notifyFailed,
    form,
    stored,
    models,
    storedModels,
    dirty,
    saving: ref(false),
    savedNote: ref(false),
    errorText: ref(''),
    errorField: ref(''),
    errorPlace: ref(''),
    loading: ref(true),
    loadFailed: ref(false),
    switching: ref(false),
    latest: new Map(),
    queue: { working: false, tail: Promise.resolve() },
  }
}

export function useResearchAdminForm() {
  const ctx = createResearchAdminContext()
  const justSaved = computed(() => ctx.savedNote.value && !ctx.dirty.value && !ctx.saving.value)
  const saveStatus = computed(() => {
    if (ctx.saving.value) return ctx.t('unsavedChanges.saving')
    if (ctx.errorPlace.value === 'save') return ''
    if (justSaved.value) return ctx.t('adminSetup.research.saved')
    if (ctx.dirty.value) return ctx.t('adminSetup.research.saveHint')
    return ''
  })
  watch(ctx.dirty, (isDirty) => {
    if (isDirty) ctx.savedNote.value = false
  })
  onMounted(() => {
    void loadResearchAdmin(ctx)
  })
  return {
    FIELD_CLASS,
    DEPTHS,
    BUDGET_FIELDS,
    LIMIT_FIELDS,
    modelSlots,
    loading: ctx.loading,
    loadFailed: ctx.loadFailed,
    load: () => loadResearchAdmin(ctx),
    form: ctx.form,
    stored: ctx.stored,
    switching: ctx.switching,
    setEnabled: (enabled: boolean) => setResearchEnabled(ctx, enabled),
    models: ctx.models,
    commitModel: (field: ModelField) => commitResearchModel(ctx, field),
    modelLabel: (model: ResearchAdmin['models'][number]) => researchModelLabel(ctx.t, model),
    commitBudget: (depth: Depth, key: BudgetKey) => commitResearchBudget(ctx, depth, key),
    commitLimit: (key: LimitKey) => commitResearchLimit(ctx, key),
    leaveField,
    errorPlace: ctx.errorPlace,
    errorText: ctx.errorText,
    saveStatus,
    justSaved,
    dirty: ctx.dirty,
    saving: ctx.saving,
    discard: () => discardResearchEdits(ctx),
    save: () => saveResearchForm(ctx),
    adoptSwitch: (next: ResearchAdmin) => adoptResearchSwitch(ctx, next),
  }
}
