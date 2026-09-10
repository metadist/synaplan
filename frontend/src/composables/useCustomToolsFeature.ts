import { getConfigSync } from '@/services/api/httpClient'

/**
 * Custom HTTP tools tab (TOOLS.CUSTOM_HTTP_ENABLED).
 *
 * Lives here instead of stores/config.ts so the flag stays OTA-deliverable.
 */
export function isCustomToolsEnabled(): boolean {
  const features = getConfigSync().features as { toolsCustomHttpEnabled?: boolean } | undefined
  return features?.toolsCustomHttpEnabled === true
}
