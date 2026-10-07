import { getConfigSync } from '@/services/api/httpClient'

/** Missing configuration keeps today's empty chat. */
export function showStoreCards(): boolean {
  return getConfigSync().chatWelcome?.showStoreCards !== false
}

export function showWidgetPromo(): boolean {
  return getConfigSync().chatWelcome?.showWidgetPromo !== false
}

export function canExportChats(): boolean {
  return getConfigSync().chatActions?.export !== false
}

export function canShareChats(): boolean {
  return getConfigSync().chatActions?.share !== false
}
