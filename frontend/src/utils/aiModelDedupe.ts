/**
 * Issue #261: dedupe the AI model catalogue so the "Available Models"
 * table shows each model ONCE, with the purposes it supports surfaced as
 * a list of selectable chips. The backend's getModels response is keyed
 * by purpose (`Partial<Record<Capability, AIModel[]>>`) so the same model
 * legitimately appears in several buckets; this helper folds those
 * buckets back into a single display row.
 *
 * The dedup key is `(service, name)` — not the model id — because the
 * BMODELS catalogue intentionally registers some models under multiple
 * ids: e.g. "Claude Opus 4.6" is two rows, one with BTAG=chat (id 160,
 * surfaced under CHAT/SORT/ANALYZE) and one with BTAG=mem (id 222,
 * surfaced under MEM). Both rows describe the same user-visible model;
 * what differs is the backend-side id we must persist when the user
 * picks a given purpose. Each chip therefore carries its own `modelId`,
 * so clicking "CHAT" stores id 160 while "MEM" stores id 222.
 */
import type { AIModel, Capability } from '@/types/ai-models'

/**
 * One selectable chip on a dedup'd row. `modelId` is the backend id to
 * persist when the user activates this purpose for the row.
 */
export type PurposeChip = { purpose: Capability; modelId: number }

export type ModelWithPurposes = AIModel & { purposes: PurposeChip[] }

export type ModelsByPurpose = Partial<Record<Capability, AIModel[]>>

/**
 * Collapse a purpose-keyed model map into one entry per (service, name).
 *
 * - Chips append in `purposeOrder` so they render in a stable canonical
 *   sequence regardless of API response ordering.
 * - The first occurrence of a given (service, name) wins as the row's
 *   canonical metadata (description / quality / icon). The backend keeps
 *   these in sync across BMODELS rows in practice; if they diverge the
 *   first row is the source of truth for display purposes only — chip
 *   clicks still route to the per-purpose id.
 * - Empty input → empty output; unknown purposes in the input map are
 *   skipped (callers control the supported `purposeOrder`).
 */
export function dedupeModelsByPurpose(
  modelsByPurpose: ModelsByPurpose,
  purposeOrder: readonly Capability[]
): ModelWithPurposes[] {
  const byKey = new Map<string, ModelWithPurposes>()

  for (const purpose of purposeOrder) {
    const models = modelsByPurpose[purpose]
    if (!models) continue
    for (const model of models) {
      const key = `${model.service}\u0000${model.name}`
      const existing = byKey.get(key)
      if (existing) {
        if (!existing.purposes.some((chip) => chip.purpose === purpose)) {
          existing.purposes.push({ purpose, modelId: model.id })
        }
      } else {
        byKey.set(key, {
          ...model,
          purposes: [{ purpose, modelId: model.id }],
        })
      }
    }
  }

  return [...byKey.values()]
}
