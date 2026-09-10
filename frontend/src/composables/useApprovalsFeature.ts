import { getConfigSync } from '@/services/api/httpClient'

/**
 * Approvals inbox + ApprovalCard (TOOLS.APPROVALS_ENABLED).
 *
 * Lives here instead of stores/config.ts so the flag stays OTA-deliverable.
 */
export function isApprovalsEnabled(): boolean {
  const features = getConfigSync().features as { toolsApprovalsEnabled?: boolean } | undefined
  return features?.toolsApprovalsEnabled === true
}
