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
