import { getErrorMessage } from '@/utils/errorMessage'

/**
 * Dev-only recovery for Pinia's "no active Pinia" error during Vite HMR.
 *
 * A hot update can run component setup while the active Pinia is briefly
 * missing. The next full load installs Pinia before mount, so a refresh
 * restores the page. Leaving that transient failure on the fatal error
 * screen forces a manual refresh and tells the user the team was notified.
 */

const STORAGE_KEY = 'synaplan.devPiniaReload'

let recoveryInProgress = false
let reloadStarted = false
let reloadPage = (): void => {
  window.location.reload()
}

export function isDevPiniaContextLoss(err: unknown): boolean {
  const message = getErrorMessage(err)
  return message != null && message.includes('getActivePinia()')
}

/** Marks an in-flight remount so a healthy-page signal cannot clear the reload guard early. */
export function beginDevPiniaRecovery(): void {
  recoveryInProgress = true
}

/** The remounted tree rendered. The next hot-update failure may reload again. */
export function finishDevPiniaRecovery(): void {
  recoveryInProgress = false
  noteDevPiniaContextHealthy()
}

/**
 * Clears the one-reload guard after the page is actually up.
 * No-op while a remount or reload is in flight, so a failure that survives
 * the reload cannot schedule another one in the same breath.
 */
export function noteDevPiniaContextHealthy(): void {
  if (recoveryInProgress || reloadStarted) return
  try {
    sessionStorage.removeItem(STORAGE_KEY)
  } catch {
    // Private mode can reject storage. The in-memory guard still stops a loop.
  }
}

/**
 * Reloads once for this failure. Returns true when the caller should not show
 * the error screen (reload started, or a reload is already underway).
 * Returns false when this page already reloaded for the same failure, so the
 * caller shows the normal error screen instead of looping.
 */
export function reloadAfterDevPiniaContextLoss(err: unknown): boolean {
  if (!import.meta.env.DEV || !isDevPiniaContextLoss(err)) return false
  if (reloadStarted) return true
  try {
    if (sessionStorage.getItem(STORAGE_KEY) === '1') return false
    sessionStorage.setItem(STORAGE_KEY, '1')
  } catch {
    // Still reload once. A stuck error screen is worse than a second refresh.
  }
  reloadStarted = true
  console.warn('Hot update lost the Pinia context. Reloading the page to restore it.')
  reloadPage()
  return true
}

/** Test hook. Restores the real page reload when called with no argument. */
export function resetDevPiniaContextRecovery(
  reload: () => void = () => {
    window.location.reload()
  }
): void {
  recoveryInProgress = false
  reloadStarted = false
  reloadPage = reload
  try {
    sessionStorage.removeItem(STORAGE_KEY)
  } catch {
    // ignore
  }
}
