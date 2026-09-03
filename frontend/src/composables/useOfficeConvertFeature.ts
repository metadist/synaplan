import { getConfigSync } from '@/services/api/httpClient'

/**
 * Office convert-to feature flag (`features.officeConvertEnabled`, from
 * OFFICE_CONVERT_URL). Off by default so office thumbnails, PDF export,
 * preview and combine stay hidden until the operator starts the Collabora
 * sidecar.
 *
 * Lives here instead of stores/config.ts on purpose: that store is on the
 * store-required list in .github/mobile-impact-policy.json, and this flag is a
 * pure web-layer concern that must stay OTA-deliverable.
 *
 * getConfigSync() reads a reactive ref, so calls inside computed() or template
 * expressions re-evaluate when the runtime config loads.
 */
export function isOfficeConvertEnabled(): boolean {
  return getConfigSync().features?.officeConvertEnabled === true
}
