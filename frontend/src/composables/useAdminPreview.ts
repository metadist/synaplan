import { useAuthStore } from '@/stores/auth'

/**
 * Features that exist only for admins until the marker is removed in code.
 *
 * Keep this list in step with `App\Service\Feature\AdminPreview::FEATURES`.
 * `isAdminPreview('<id>')` is true only for an admin. A feature id that is
 * not listed is hidden, so a typo never shows the surface to everyone.
 * While the list is empty, `AdminPreviewFeature` is `never`, so a call site
 * left behind after a release fails the type check.
 *
 * Reads `stores/auth.ts` and does not change it, so this stays OTA-deliverable.
 * `useAuthStore()` is called at call time: a `computed()` or a template
 * expression re-evaluates when the signed-in user changes.
 */
const FEATURES = [] as const satisfies readonly string[]

export type AdminPreviewFeature = (typeof FEATURES)[number]

export function isAdminPreview(
  feature: AdminPreviewFeature,
  features: readonly string[] = FEATURES
): boolean {
  if (!features.includes(feature)) {
    return false
  }

  return useAuthStore().isAdmin
}
