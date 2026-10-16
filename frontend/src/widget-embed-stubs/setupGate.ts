/**
 * Widget-build stand-in for `@/router/setupGate`.
 * See `router.ts` — the embed must not load the SPA setup wizard graph.
 */
export async function ensureWizardRequired(): Promise<boolean> {
  return false
}

export function invalidateSetupWizardRequired(): void {}

export function isSetupRecheckRoute(): boolean {
  return false
}

export const SETUP_ROUTE = 'setup'
