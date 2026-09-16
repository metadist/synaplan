import { getConfigSync } from '@/services/api/httpClient'

/**
 * Saved Task Steps editor and inbound webhook trigger (WORKFLOWS.BUILDER_ENABLED).
 *
 * Lives here instead of stores/config.ts so the flag stays OTA-deliverable.
 */
export function isWorkflowsBuilderEnabled(): boolean {
  const features = getConfigSync().features as { workflowsBuilderEnabled?: boolean } | undefined
  return features?.workflowsBuilderEnabled === true
}
