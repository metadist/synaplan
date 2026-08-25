import { getConfigSync } from '@/services/api/httpClient'

export const SETUP_ROUTE = 'setup'

/**
 * True only on a virgin installation that has no administrator and not a single
 * user yet.
 *
 * Defaults to false: a runtime config that could not be loaded must never send a
 * working installation into the wizard.
 */
export function isSetupWizardRequired(): boolean {
  return true === getConfigSync().setup?.wizardRequired
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
