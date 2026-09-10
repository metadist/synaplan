import { getConfigSync } from '@/services/api/httpClient'

/**
 * Feature-module states from the runtime config (`modules.<id>.configured` /
 * `.gated`, see `App\Module\ModuleRegistry` and GET /api/v1/config/runtime).
 *
 * Defaults are deliberately permissive: when the backend predates the `modules`
 * key, or does not know the id, the module counts as configured and not gated,
 * so an older server renders exactly what it rendered before. Only an explicit
 * `configured: false` hides a surface.
 *
 * Lives here instead of stores/config.ts on purpose: that store is on the
 * store-required list in .github/mobile-impact-policy.json, and these flags are
 * a pure web-layer concern that must stay OTA-deliverable.
 *
 * getConfigSync() reads a reactive ref, so calls inside computed() or template
 * expressions re-evaluate when the runtime config loads.
 */
export type ModuleId =
  | 'tika'
  | 'docling'
  | 'office_convert'
  | 'searxng'
  | 'piper_tts'
  | 'local_ai'
  | 'higgsfield'
  | 'google_ai'
  | 'thehive'
  | 'stripe_billing'
  | 'mobile_iap'
  | 'whatsapp'

interface ModuleState {
  configured?: unknown
  gated?: unknown
}

function moduleState(id: string): ModuleState | undefined {
  const modules = (getConfigSync() as { modules?: Record<string, ModuleState> }).modules
  return modules?.[id]
}

/** False only when the backend explicitly reports the module as not configured. */
export function isModuleConfigured(id: ModuleId | string): boolean {
  const configured = moduleState(id)?.configured
  return typeof configured === 'boolean' ? configured : true
}

/** True only when the backend explicitly reports the module's gate as on. */
export function isModuleGated(id: ModuleId | string): boolean {
  return moduleState(id)?.gated === true
}
