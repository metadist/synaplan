/**
 * Admin switches for how much the chat narrates while an answer is prepared.
 *
 * Read straight from the runtime config so `stores/config.ts` (a mobile
 * store-required path) stays untouched. All three default ON; an older
 * backend that does not send the block behaves like a fresh install.
 */
import { getConfigSync } from '@/services/api/httpClient'

export interface ProgressNarrationSwitches {
  /** Ordered step list with finished phases (false: only the current phase). */
  steps: boolean
  /** Name the model and provider doing the work (false: generic wording). */
  models: boolean
  /** Step durations and the live elapsed counter. */
  timings: boolean
}

export function progressNarrationSwitches(): ProgressNarrationSwitches {
  const block = getConfigSync().progressNarration
  return {
    steps: block?.steps ?? true,
    models: block?.models ?? true,
    timings: block?.timings ?? true,
  }
}
