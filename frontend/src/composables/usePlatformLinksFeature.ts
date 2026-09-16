import { getConfigSync } from '@/services/api/httpClient'

/**
 * Partner-platform linking flag (PLATFORM_LINKS.ENABLED). Off by default.
 * The Outlook `/connect/platform?client=outlook` path is not gated here.
 *
 * Lives here instead of stores/config.ts so the flag stays OTA-deliverable.
 */
export function isPlatformLinksEnabled(): boolean {
  return getConfigSync().features?.platformLinksEnabled === true
}
