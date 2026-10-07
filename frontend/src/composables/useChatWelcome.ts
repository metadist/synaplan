import { getConfigSync } from '@/services/api/httpClient'

type WelcomeFlags = {
  chatWelcome?: { showStoreCards?: boolean; showWidgetPromo?: boolean }
  chatActions?: { export?: boolean; share?: boolean }
}

function flags(): WelcomeFlags {
  return getConfigSync() as WelcomeFlags
}

/** Missing configuration keeps today's empty chat. */
export function showStoreCards(): boolean {
  return flags().chatWelcome?.showStoreCards !== false
}

export function showWidgetPromo(): boolean {
  return flags().chatWelcome?.showWidgetPromo !== false
}

export function canExportChats(): boolean {
  return flags().chatActions?.export !== false
}

export function canShareChats(): boolean {
  return flags().chatActions?.share !== false
}
