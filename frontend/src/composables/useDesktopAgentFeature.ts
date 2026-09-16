import { getConfigSync } from '@/services/api/httpClient'

/**
 * Synaplan Desktop feature flag (DESKTOP_AGENT.ENABLED, delivered via runtime
 * config). Off by default, so the whole Desktop surface (nav child, page,
 * composer action) stays invisible on every install until an operator turns it
 * on (invariant C8, mirrored server-side by DesktopController's 404 guard).
 *
 * Lives here instead of stores/config.ts on purpose: that store is on the
 * store-required list in .github/mobile-impact-policy.json, and this flag is a
 * pure web-layer concern that must stay OTA-deliverable.
 *
 * getConfigSync() reads a reactive ref, so calls inside computed() or template
 * expressions re-evaluate when the runtime config loads.
 */
export function isDesktopAgentEnabled(): boolean {
  return getConfigSync().features?.desktopAgentEnabled === true
}
