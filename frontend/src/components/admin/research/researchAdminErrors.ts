import { ApiError } from '@/services/api/httpClient'

export interface ResearchRefusal {
  code: string
  field: string | null
}

/** The `{ error: { code, field } }` body the research admin endpoints send. */
export function researchRefusal(error: unknown): ResearchRefusal | null {
  if (!(error instanceof ApiError)) return null
  const nested = error.details?.error
  if (!nested || typeof nested !== 'object') return null
  const code = 'code' in nested && typeof nested.code === 'string' ? nested.code : ''
  const field = 'field' in nested && typeof nested.field === 'string' ? nested.field : null
  return code ? { code, field } : null
}

export const REFUSAL_KEYS: Record<string, string> = {
  env_locked: 'adminSetup.research.errors.envLocked',
  research_off: 'adminSetup.research.errors.researchOff',
  sample_running: 'adminSetup.research.errors.sampleRunning',
  too_many: 'adminSetup.research.errors.tooMany',
  unavailable: 'adminSetup.research.errors.unavailable',
  model_unavailable: 'adminSetup.research.errors.modelUnavailable',
  invalid: 'adminSetup.research.errors.invalid',
}
