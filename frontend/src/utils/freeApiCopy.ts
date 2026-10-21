import type { FreeApiStatus } from '@/services/api/freeApiApi'
import { ApiError } from '@/services/api/httpClient'

/** The banner names the end from this many days before it (days 12–14 of 14). */
const ENDS_SOON_DAYS = 3

/** Characters of the install id shown on the card; enough to tell installs apart. */
const INSTALL_ID_PREVIEW = 4

type Translate = (key: string, named?: Record<string, unknown>, plural?: number) => string

export interface FreeApiCardRow {
  id: string
  label: string
  value: string
}

/** Start failures with a sentence of their own; every other failure reads `freeApi.startError.unknown`. */
const START_ERRORS: Record<string, string> = {
  freeapi_unreachable: 'unreachable',
  freeapi_chat_ready: 'chatReady',
  freeapi_own_endpoint: 'ownEndpoint',
  freeapi_already_active: 'alreadyActive',
  freeapi_throttled: 'throttled',
  freeapi_full_today: 'fullToday',
  freeapi_paused: 'paused',
  freeapi_blocked: 'blocked',
  freeapi_expired: 'expired',
  freeapi_upgrade_required: 'upgradeRequired',
  freeapi_setup_incomplete: 'setupIncomplete',
}

/** The i18n key that says why the Synaplan Demo was not started. */
export function freeApiStartErrorKey(error: unknown): string {
  const code = error instanceof ApiError ? error.code : undefined
  const key = code !== undefined ? START_ERRORS[code] : undefined

  return `freeApi.startError.${key ?? 'unknown'}`
}

export function freeApiEndsSoon(daysLeft: number | null): boolean {
  return daysLeft !== null && daysLeft >= 0 && daysLeft <= ENDS_SOON_DAYS
}

function formatDay(unixSeconds: number, locale: string): string {
  return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' }).format(
    new Date(unixSeconds * 1000)
  )
}

/** The facts on the Demo card, in reading order; rows without data are left out. */
export function freeApiCardRows(
  status: FreeApiStatus,
  t: Translate,
  locale: string
): FreeApiCardRow[] {
  const rows: FreeApiCardRow[] = []
  const today = status.today

  rows.push({
    id: 'today',
    label: t('freeApi.card.today'),
    value: today
      ? t('freeApi.card.todayValue', {
          messages: today.messagesRemaining,
          messagesLimit: today.messagesLimit,
          images: today.imagesRemaining,
          imagesLimit: today.imagesLimit,
        })
      : t('freeApi.card.todayUnknown'),
  })

  if (status.daysLeft !== null && status.expiresAt !== null) {
    rows.push({
      id: 'ends',
      label: t('freeApi.card.ends'),
      value: t(
        'freeApi.card.endsValue',
        { days: status.daysLeft, date: formatDay(status.expiresAt, locale) },
        status.daysLeft
      ),
    })
  }

  rows.push({
    id: 'includes',
    label: t('freeApi.card.includes'),
    value: t('freeApi.card.includesValue'),
  })
  rows.push({
    id: 'not-included',
    label: t('freeApi.card.notIncluded'),
    value: t('freeApi.card.notIncludedValue'),
  })

  if (status.activatedAt !== null) {
    const date = formatDay(status.activatedAt, locale)
    // Who started it is shown to admins only; for them a missing name means the console.
    let started = date
    if (status.activatedBy) started = t('freeApi.card.startedBy', { date, who: status.activatedBy })
    else if (status.canManage) started = t('freeApi.card.startedConsole', { date })
    const install = status.installId
      ? ` · ${t('freeApi.card.install', { id: `${status.installId.slice(0, INSTALL_ID_PREVIEW)}…` })}`
      : ''
    rows.push({ id: 'started', label: t('freeApi.card.started'), value: `${started}${install}` })
  }

  return rows
}
