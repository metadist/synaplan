import type { AIModel, Capability } from '@/types/ai-models'

export const DEFAULT_AI_MODEL_NAME = 'gpt-oss-120b'
export const DEFAULT_AI_MODEL_SERVICE = 'Groq'
export const DEFAULT_AI_MODEL = `${DEFAULT_AI_MODEL_NAME} (${DEFAULT_AI_MODEL_SERVICE})`

export function findModelIdByString(
  models: Partial<Record<Capability, AIModel[]>>,
  modelString: string
): number {
  for (const group of Object.values(models)) {
    if (group) {
      const found = group.find((m) => `${m.name} (${m.service})` === modelString)
      if (found) return found.id
    }
  }
  return -1
}

export function findDefaultModelId(models: Partial<Record<Capability, AIModel[]>>): number {
  return findModelIdByString(models, DEFAULT_AI_MODEL)
}

/**
 * Model id to persist for a picked display string, keeping `storedId` when the
 * string cannot be resolved.
 *
 * `/config/models` omits models whose provider is unavailable on this
 * installation, so a pinned model can be absent from `models` and its
 * `name (service)` string unresolvable. Without the fallback the save would
 * replace the pin with the "no model" sentinel and lose the configuration.
 */
export function resolveModelIdForSave(
  models: Partial<Record<Capability, AIModel[]>>,
  modelString: string,
  storedId: unknown
): number {
  const resolved = findModelIdByString(models, modelString)
  if (resolved > 0) {
    return resolved
  }

  return typeof storedId === 'number' && storedId > 0 ? storedId : resolved
}
