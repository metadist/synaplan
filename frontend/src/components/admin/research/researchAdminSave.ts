import { nextTick, type ComputedRef, type Ref } from 'vue'
import {
  fetchResearchAdmin,
  saveResearchAdmin,
  type ResearchAdmin,
} from '@/services/api/researchAdminApi'
import {
  BUDGET_FIELDS,
  DEPTHS,
  LIMIT_FIELDS,
  firstOutOfRange,
  modelSelection,
  modelSlots,
  outOfRange,
  researchFieldLabel,
  researchRefusalText,
  type BudgetKey,
  type Depth,
  type LimitKey,
  type ModelField,
  type ResearchTranslate,
} from './researchAdminFields'

type SaveJob = {
  field: string
  body: Record<string, unknown>
  unchanged: () => boolean
  patch: (next: ResearchAdmin) => void
}

export type ResearchAdminContext = {
  t: ResearchTranslate
  notifySaved: (message: string) => void
  notifyFailed: (message: string) => void
  form: Ref<ResearchAdmin | null>
  stored: Ref<ResearchAdmin | null>
  models: Ref<Record<ModelField, string>>
  storedModels: ComputedRef<Record<ModelField, string>>
  dirty: ComputedRef<boolean>
  saving: Ref<boolean>
  savedNote: Ref<boolean>
  errorText: Ref<string>
  errorField: Ref<string>
  errorPlace: Ref<'switch' | 'save' | ''>
  loading: Ref<boolean>
  loadFailed: Ref<boolean>
  switching: Ref<boolean>
  latest: Map<string, SaveJob>
  queue: { working: boolean; tail: Promise<void> }
}

export function applyResearchAdmin(ctx: ResearchAdminContext, next: ResearchAdmin): void {
  ctx.stored.value = next
  ctx.form.value = JSON.parse(JSON.stringify(next)) as ResearchAdmin
  ctx.models.value = { ...modelSelection(ctx.stored.value) }
}

export async function loadResearchAdmin(ctx: ResearchAdminContext): Promise<void> {
  ctx.loading.value = true
  ctx.loadFailed.value = false
  try {
    applyResearchAdmin(ctx, await fetchResearchAdmin())
  } catch {
    ctx.loadFailed.value = true
  } finally {
    ctx.loading.value = false
  }
}

/** A switch changed on the server: take its view, keep the unsaved edits of the form. */
export function adoptResearchSwitch(ctx: ResearchAdminContext, next: ResearchAdmin): void {
  if (!ctx.form.value) return
  const keepEdits = ctx.dirty.value
    ? { budgets: ctx.form.value.budgets, limits: ctx.form.value.limits }
    : {}
  const picked = { ...ctx.models.value }
  applyResearchAdmin(ctx, next)
  Object.assign(ctx.form.value, keepEdits)
  ctx.models.value = picked
}

export async function setResearchEnabled(
  ctx: ResearchAdminContext,
  enabled: boolean
): Promise<void> {
  if (!ctx.form.value || !ctx.stored.value) return
  ctx.switching.value = true
  ctx.errorPlace.value = ''
  try {
    adoptResearchSwitch(ctx, await saveResearchAdmin({ enabled }))
  } catch (error) {
    ctx.errorPlace.value = 'switch'
    ctx.errorText.value = researchRefusalText(
      ctx.t,
      error,
      'adminSetup.research.errors.switchFailed'
    )
  } finally {
    ctx.switching.value = false
  }
}

export function discardResearchEdits(ctx: ResearchAdminContext): void {
  if (!ctx.stored.value || ctx.saving.value) return
  ctx.latest.clear()
  ctx.form.value = JSON.parse(JSON.stringify(ctx.stored.value)) as ResearchAdmin
  ctx.models.value = { ...ctx.storedModels.value }
  ctx.errorPlace.value = ''
  ctx.errorText.value = ''
  ctx.errorField.value = ''
  ctx.savedNote.value = false
}

function failSave(ctx: ResearchAdminContext, message: string, field = ''): void {
  const repeat = ctx.errorPlace.value === 'save' && ctx.errorText.value === message
  ctx.savedNote.value = false
  ctx.errorPlace.value = 'save'
  ctx.errorText.value = message
  ctx.errorField.value = field
  if (!repeat) ctx.notifyFailed(message)
}

export function leaveField(event: KeyboardEvent): void {
  event.preventDefault()
  const target = event.target
  if (target instanceof HTMLElement) target.blur()
}

function restoreNewer(
  ctx: ResearchAdminContext,
  draft: ResearchAdmin,
  draftModels: Record<ModelField, string>,
  live: ResearchAdmin,
  liveModels: Record<ModelField, string>
): void {
  if (!ctx.form.value) return
  for (const depth of DEPTHS) {
    for (const field of BUDGET_FIELDS) {
      if (live.budgets[depth][field.key] !== draft.budgets[depth][field.key]) {
        ctx.form.value.budgets[depth][field.key] = live.budgets[depth][field.key]
      }
    }
  }
  for (const field of LIMIT_FIELDS) {
    if (live.limits[field.key] !== draft.limits[field.key]) {
      ctx.form.value.limits[field.key] = live.limits[field.key]
    }
  }
  for (const slot of modelSlots) {
    if (liveModels[slot.field] !== draftModels[slot.field]) {
      ctx.models.value[slot.field] = liveModels[slot.field]
    }
  }
}

function schedule(ctx: ResearchAdminContext, id: string, job: SaveJob): Promise<void> {
  ctx.latest.set(id, job)
  ctx.savedNote.value = false
  return kick(ctx)
}

function kick(ctx: ResearchAdminContext): Promise<void> {
  if (ctx.queue.working) return ctx.queue.tail
  ctx.queue.working = true
  ctx.saving.value = true
  const run = flush(ctx).finally(() => {
    ctx.queue.working = false
    if (ctx.latest.size > 0) {
      void kick(ctx)
    } else {
      ctx.saving.value = false
    }
  })
  ctx.queue.tail = run
  return run
}

async function flush(ctx: ResearchAdminContext): Promise<void> {
  try {
    const storedSomething = await runQueued(ctx)
    if (storedSomething && ctx.latest.size === 0 && !ctx.dirty.value) {
      ctx.savedNote.value = true
      ctx.notifySaved(ctx.t('unsavedChanges.saved'))
    }
  } catch (error) {
    ctx.latest.clear()
    failSave(ctx, researchRefusalText(ctx.t, error, 'adminSetup.research.errors.saveFailed'))
  }
}

async function runQueued(ctx: ResearchAdminContext): Promise<boolean> {
  let storedSomething = false
  while (ctx.latest.size > 0) {
    const id = ctx.latest.keys().next().value
    if (!id) break
    const job = ctx.latest.get(id)
    ctx.latest.delete(id)
    if (!job || job.unchanged()) continue
    const next = await saveResearchAdmin(job.body)
    ctx.stored.value = next
    job.patch(next)
    if (ctx.errorField.value === '' || ctx.errorField.value === job.field || job.field === 'form') {
      ctx.errorPlace.value = ''
      ctx.errorText.value = ''
      ctx.errorField.value = ''
    }
    storedSomething = true
  }
  return storedSomething
}

export async function commitResearchBudget(
  ctx: ResearchAdminContext,
  depth: Depth,
  key: BudgetKey
): Promise<void> {
  await nextTick()
  const field = BUDGET_FIELDS.find((item) => item.key === key)
  if (!ctx.form.value || !ctx.stored.value || !field) return
  const value = ctx.form.value.budgets[depth][key]
  const id = `budgets.${depth}.${key}`
  if (value === ctx.stored.value.budgets[depth][key]) return
  if (outOfRange(value, field.min, field.max, field.step === 1)) {
    failSave(
      ctx,
      ctx.t('adminSetup.research.errors.outOfRange', { field: researchFieldLabel(ctx.t, id) }),
      id
    )
    return
  }
  const sent = value
  return schedule(ctx, id, {
    field: id,
    body: { budgets: { [depth]: { [key]: sent } } },
    unchanged: () => ctx.stored.value?.budgets[depth][key] === sent,
    patch: (next) => {
      if (ctx.form.value && ctx.form.value.budgets[depth][key] === sent) {
        ctx.form.value.budgets[depth][key] = next.budgets[depth][key]
      }
    },
  })
}

export async function commitResearchLimit(ctx: ResearchAdminContext, key: LimitKey): Promise<void> {
  await nextTick()
  const field = LIMIT_FIELDS.find((item) => item.key === key)
  if (!ctx.form.value || !ctx.stored.value || !field) return
  const value = ctx.form.value.limits[key]
  const id = `limits.${key}`
  if (value === ctx.stored.value.limits[key]) return
  if (outOfRange(value, field.min, field.max, true)) {
    failSave(
      ctx,
      ctx.t('adminSetup.research.errors.outOfRange', { field: researchFieldLabel(ctx.t, id) }),
      id
    )
    return
  }
  const sent = value
  return schedule(ctx, id, {
    field: id,
    body: { limits: { [key]: sent } },
    unchanged: () => ctx.stored.value?.limits[key] === sent,
    patch: (next) => {
      if (ctx.form.value && ctx.form.value.limits[key] === sent) {
        ctx.form.value.limits[key] = next.limits[key]
      }
    },
  })
}

export async function commitResearchModel(
  ctx: ResearchAdminContext,
  field: ModelField
): Promise<void> {
  await nextTick()
  if (!ctx.form.value || !ctx.stored.value) return
  const value = ctx.models.value[field]
  if (value === ctx.storedModels.value[field]) return
  return schedule(ctx, field, {
    field,
    body: { [field]: value === '' ? 0 : Number(value) },
    unchanged: () => ctx.storedModels.value[field] === value,
    patch: (next) => {
      if (ctx.models.value[field] === value) {
        ctx.models.value[field] = next[field] ? String(next[field]) : ''
      }
    },
  })
}

export function saveResearchForm(ctx: ResearchAdminContext): Promise<void> {
  if (!ctx.form.value) return Promise.resolve()
  const invalid = firstOutOfRange(ctx.form.value)
  if (invalid) {
    failSave(
      ctx,
      ctx.t('adminSetup.research.errors.outOfRange', { field: researchFieldLabel(ctx.t, invalid) }),
      invalid
    )
    return Promise.resolve()
  }
  if (!ctx.dirty.value) return Promise.resolve()
  const current = ctx.form.value
  const budgets: Record<string, ResearchAdmin['budgets']['quick']> = {}
  for (const depth of DEPTHS) budgets[depth] = current.budgets[depth]
  const body: Record<string, unknown> = { limits: current.limits, budgets }
  for (const slot of modelSlots) {
    if (ctx.models.value[slot.field] !== ctx.storedModels.value[slot.field]) {
      body[slot.field] =
        ctx.models.value[slot.field] === '' ? 0 : Number(ctx.models.value[slot.field])
    }
  }
  const snapshot = JSON.parse(JSON.stringify(current)) as ResearchAdmin
  const snapshotModels = { ...ctx.models.value }
  return schedule(ctx, 'form', {
    field: 'form',
    body,
    unchanged: () => !ctx.dirty.value,
    patch: (next) => {
      const live = ctx.form.value
        ? (JSON.parse(JSON.stringify(ctx.form.value)) as ResearchAdmin)
        : null
      const liveModels = { ...ctx.models.value }
      applyResearchAdmin(ctx, next)
      if (live) restoreNewer(ctx, snapshot, snapshotModels, live, liveModels)
    },
  })
}
