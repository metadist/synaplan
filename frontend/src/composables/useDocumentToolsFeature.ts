/**
 * Structured office editing flag (`features.documentToolsEnabled`).
 * Default-off: version history and combine-as-office stay hidden.
 */
import { getConfigSync } from '@/services/api/httpClient'

export function isDocumentToolsEnabled(): boolean {
  return getConfigSync().features?.documentToolsEnabled === true
}
