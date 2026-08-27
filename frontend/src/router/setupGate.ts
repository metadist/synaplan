import { getConfig, getConfigSync } from '@/services/api/httpClient'
import { getSetupState } from '@/services/api/setupApi'

export const SETUP_ROUTE = 'setup'

/**
 * Public entry routes a visitor hits after `app:setup:reset` or a fresh tab.
 * These must re-ask the server instead of trusting a runtime config that was
 * loaded before the CLI wiped the installation.
 */
const SETUP_RECHECK_ROUTES = new Set([
  'chat',
  'login',
  'register',
  SETUP_ROUTE,
  'forgot-password',
  'reset-password',
  'logged-out',
  'verify-email',
])

/** Last answer from `/api/v1/setup/state` (or runtime config). `null` = unknown. */
let cachedRequired: boolean | null = null
let inflight: Promise<boolean> | null = null

export function isSetupRecheckRoute(routeName: string | symbol | null | undefined): boolean {
  return 'string' === typeof routeName && SETUP_RECHECK_ROUTES.has(routeName)
}

/**
 * False only when the operator set `SETUP_WIZARD_ENABLED=false`. That is the
 * SSO/OIDC deployment: the administrator arrives through IdP roles, no local
 * account is ever created, and an empty installation is a normal steady state
 * rather than something to be set up.
 *
 * Defaults to true when the field is absent, so an older backend keeps the
 * behaviour it has today.
 */
export function isSetupWizardEnabled(): boolean {
  return false !== getConfigSync().setup?.wizardEnabled
}

/**
 * True only on a virgin installation that has no administrator and not a single
 * user yet.
 *
 * Defaults to false: a runtime config that could not be loaded must never send a
 * working installation into the wizard. After a successful `/setup/state` probe
 * the dedicated flag wins over a stale runtime config (CLI reset in another
 * process leaves the SPA holding `wizardRequired: false`).
 */
export function isSetupWizardRequired(): boolean {
  if (!isSetupWizardEnabled()) {
    return false
  }

  if (true === getConfigSync().setup?.wizardRequired) {
    return true
  }

  return true === cachedRequired
}

/**
 * Drop the in-memory answer. Call this when the wizard closes so the next
 * navigation does not bounce back into `/setup`.
 */
export function invalidateSetupWizardRequired(): void {
  cachedRequired = null
  inflight = null
}

/**
 * Resolves whether the installation still needs the first-run wizard.
 *
 * Runtime config is the fast path when it already says yes. When it says no —
 * including the default for a missing/failed load — `/api/v1/setup/state` is
 * the source of truth, because `app:setup:reset` cannot clear the SPA cache.
 *
 * `fresh` skips the in-memory answer so `/`, `/login` and `/setup` notice a
 * reset that happened while this tab stayed open.
 */
export async function ensureWizardRequired(options: { fresh?: boolean } = {}): Promise<boolean> {
  if (!options.fresh && null !== cachedRequired) {
    return cachedRequired
  }

  if (null !== inflight) {
    return inflight
  }

  inflight = resolveWizardRequired().finally(() => {
    inflight = null
  })

  return inflight
}

async function resolveWizardRequired(): Promise<boolean> {
  await getConfig()

  // An operator who switched the wizard off has answered this question for good.
  // Probing `/setup/state` on every entry navigation would be pure noise on the
  // SSO/OIDC installations that run permanently without local accounts.
  if (!isSetupWizardEnabled()) {
    cachedRequired = false
    return false
  }

  if (true === getConfigSync().setup?.wizardRequired) {
    cachedRequired = true
    return true
  }

  let timeoutId: ReturnType<typeof setTimeout> | undefined
  try {
    const state = await Promise.race([
      getSetupState(),
      new Promise<never>((_, reject) => {
        timeoutId = setTimeout(() => reject(new Error('setup state timeout')), 3000)
      }),
    ])
    cachedRequired = true === state.wizardRequired
    return cachedRequired
  } catch {
    cachedRequired = true === getConfigSync().setup?.wizardRequired
    return cachedRequired
  } finally {
    if (undefined !== timeoutId) {
      clearTimeout(timeoutId)
    }
  }
}

/**
 * Decides what a navigation must do about an installation that has not been set
 * up yet.
 *
 * - `force`: send the visitor to the setup wizard. There is nothing else to
 *   show — the backend answers 503 SETUP_REQUIRED for every other route, so a
 *   login page would only produce an error the visitor cannot act on.
 * - `release`: the wizard is open on an installation that does not need it. That
 *   happens on a normal instance whose URL someone typed by hand, and after the
 *   wizard's last step, when it is time to enter the app.
 * - `null`: nothing to do.
 *
 * MOBILE-APP SEAM (first-run onboarding): the native welcome page is exempt from
 * `force`. It is the page that lets the app point itself at a different server,
 * so forcing it into the wizard of the CURRENT server would trap a user whose
 * only mistake was aiming the app at a fresh instance.
 */
export function resolveSetupGate(navigation: {
  wizardRequired: boolean
  routeName: string | symbol | null | undefined
  isNativeOnboarding: boolean
}): 'force' | 'release' | null {
  const onWizard = SETUP_ROUTE === navigation.routeName

  if (navigation.wizardRequired) {
    if (onWizard || navigation.isNativeOnboarding) {
      return null
    }

    return 'force'
  }

  return onWizard ? 'release' : null
}
