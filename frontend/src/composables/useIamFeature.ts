import { getConfigSync } from '@/services/api/httpClient'

/**
 * IAM groups feature flag (IAM.GROUPS_ENABLED, delivered via runtime config).
 *
 * Lives here instead of stores/config.ts on purpose: that store is on the
 * store-required list in .github/mobile-impact-policy.json, and this flag is a
 * pure web-layer concern that must stay OTA-deliverable.
 *
 * getConfigSync() reads a reactive ref, so calls inside computed() or template
 * expressions re-evaluate when the runtime config loads.
 */
export function isIamGroupsEnabled(): boolean {
  return getConfigSync().features?.iamGroups === true
}
