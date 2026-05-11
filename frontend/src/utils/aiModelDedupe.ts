/**
 * Issue #261: dedupe the AI model catalogue so the "Available Models"
 * table shows each model ONCE, with the purposes it supports surfaced as
 * a list of selectable chips. The backend's getModels response is keyed
 * by purpose (`Partial<Record<Capability, AIModel[]>>`) so the same model
 * legitimately appears in several buckets; this helper folds those
 * buckets back into a single row per model id.
 */
import type { AIModel, Capability } from '@/types/ai-models'

export type ModelWithPurposes = AIModel & { purposes: Capability[] }

export type ModelsByPurpose = Partial<Record<Capability, AIModel[]>>

/**
 * Collapse a purpose-keyed model map into one entry per model.
 *
 * - `purposes` is appended in `purposeOrder` so the chips render in a
 *   stable, canonical sequence regardless of how the API returned them.
 * - The first occurrence of a given id is treated as the canonical model
 *   metadata (name / service / quality / …). The backend's seed data
 *   keeps these identical across purposes, but we don't gamble on it.
 * - Empty input → empty output; unknown purposes in the input map are
 *   skipped (callers control the supported `purposeOrder`).
 */
export function dedupeModelsByPurpose(
  modelsByPurpose: ModelsByPurpose,
  purposeOrder: readonly Capability[]
): ModelWithPurposes[] {
  const byId = new Map<number, ModelWithPurposes>()

  for (const purpose of purposeOrder) {
    const models = modelsByPurpose[purpose]
    if (!models) continue
    for (const model of models) {
      const existing = byId.get(model.id)
      if (existing) {
        if (!existing.purposes.includes(purpose)) {
          existing.purposes.push(purpose)
        }
      } else {
        byId.set(model.id, { ...model, purposes: [purpose] })
      }
    }
  }

  return [...byId.values()]
}
