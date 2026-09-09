import { getConfigSync } from '@/services/api/httpClient'

/**
 * Bundle export/import flag (BUNDLE.ENABLED).
 * Lives here so stores/config.ts stays off the store-required list.
 */
export function isBundleEnabled(): boolean {
  return getConfigSync().features?.bundleEnabled === true
}
