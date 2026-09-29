import { useAuthStore } from '@/stores/auth'

/**
 * Features that exist only for admins until the marker is removed in code.
 *
 * Keep this list in step with `App\Service\Feature\AdminPreview::FEATURES`.
 * `isAdminPreview('telegram')` is true only for an admin. A feature id that
 * is not listed is hidden, so a typo never shows the surface to everyone.
 *
 * Reads `stores/auth.ts` and does not change it, so this stays OTA-deliverable.
 * `useAuthStore()` is called at call time: a `computed()` or a template
 * expression re-evaluates when the signed-in user changes.
 */
export type AdminPreviewFeature = 'telegram'

const FEATURES: readonly AdminPreviewFeature[] = ['telegram']

export function isAdminPreview(feature: AdminPreviewFeature): boolean {
  if (!FEATURES.includes(feature)) {
    return false
  }

  return useAuthStore().isAdmin
}
