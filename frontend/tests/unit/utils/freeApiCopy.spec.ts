import { describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import { ApiError } from '@/services/api/httpClient'
import type { FreeApiStatus } from '@/services/api/freeApiApi'
import { freeApiCardRows, freeApiEndsSoon, freeApiStartErrorKey } from '@/utils/freeApiCopy'

const t = (key: string, named: Record<string, unknown> = {}, plural?: number) =>
  plural === undefined ? i18n.global.t(key, named) : i18n.global.t(key, named, plural)

function activeStatus(overrides: Partial<FreeApiStatus> = {}): FreeApiStatus {
  return {
    available: true,
    active: true,
    canManage: true,
    server: 'web.synaplan.com',
    expiresAt: Date.UTC(2026, 9, 30, 12) / 1000,
    daysLeft: 11,
    activatedAt: Date.UTC(2026, 9, 17, 12) / 1000,
    activatedBy: 'admin@synaplan.com',
    installId: '9f3c2a10-5b7e-4c1d-8a2f-0e6b4d3c2a19',
    limits: { messagesPerDay: 40, imagesPerDay: 5 },
    today: {
      messagesRemaining: 27,
      messagesLimit: 40,
      imagesRemaining: 3,
      imagesLimit: 5,
      resetsAt: null,
    },
    refusal: null,
    ...overrides,
  }
}

const CODES = [
  'freeapi_unreachable',
  'freeapi_chat_ready',
  'freeapi_own_endpoint',
  'freeapi_already_active',
  'freeapi_throttled',
  'freeapi_limit_reached',
  'freeapi_full_today',
  'freeapi_paused',
  'freeapi_blocked',
  'freeapi_expired',
  'freeapi_upgrade_required',
  'freeapi_setup_incomplete',
]

describe('freeApiStartErrorKey', () => {
  it.each(CODES)('gives %s a sentence of its own in every locale', (code) => {
    const key = freeApiStartErrorKey(new ApiError(409, 'x', code))

    expect(key).not.toBe('freeApi.startError.unknown')
    for (const locale of ['de', 'en', 'es', 'fr', 'tr']) {
      expect(i18n.global.te(key, locale)).toBe(true)
    }
  })

  it('falls back to the generic sentence for anything else', () => {
    expect(freeApiStartErrorKey(new ApiError(502, 'x', 'freeapi_invalid_response'))).toBe(
      'freeApi.startError.unknown'
    )
    expect(freeApiStartErrorKey(new ApiError(500, 'x'))).toBe('freeApi.startError.unknown')
    expect(freeApiStartErrorKey(new Error('network'))).toBe('freeApi.startError.unknown')
  })
})

describe('freeApiEndsSoon', () => {
  it('names the end only in the last three days', () => {
    expect(freeApiEndsSoon(null)).toBe(false)
    expect(freeApiEndsSoon(4)).toBe(false)
    expect(freeApiEndsSoon(3)).toBe(true)
    expect(freeApiEndsSoon(0)).toBe(true)
  })
})

describe('freeApiCardRows', () => {
  const byId = (rows: ReturnType<typeof freeApiCardRows>) =>
    Object.fromEntries(rows.map((row) => [row.id, row.value]))

  it("lists today's use, the end, what is included and who started it", () => {
    i18n.global.locale.value = 'en'
    const rows = byId(freeApiCardRows(activeStatus(), t, 'en'))

    expect(rows.today).toBe(
      t('freeApi.card.todayValue', { messages: 27, messagesLimit: 40, images: 3, imagesLimit: 5 })
    )
    expect(rows.ends).toBe(t('freeApi.card.endsValue', { days: 11, date: 'Oct 30' }, 11))
    expect(rows.includes).toBe(t('freeApi.card.includesValue'))
    expect(rows['not-included']).toBe(t('freeApi.card.notIncludedValue'))
    expect(rows.started).toBe(
      `${t('freeApi.card.startedBy', { date: 'Oct 17', who: 'admin@synaplan.com' })} · ${t('freeApi.card.install', { id: '9f3c…' })}`
    )
  })

  it('says when the server did not report today and when the console started it', () => {
    const rows = byId(
      freeApiCardRows(activeStatus({ today: null, activatedBy: null, installId: null }), t, 'en')
    )

    expect(rows.today).toBe(t('freeApi.card.todayUnknown'))
    expect(rows.started).toBe(t('freeApi.card.startedConsole', { date: 'Oct 17' }))
  })

  it('shows a member only the date, never a guess about who started it', () => {
    const rows = byId(
      freeApiCardRows(
        activeStatus({ canManage: false, activatedBy: null, installId: null }),
        t,
        'en'
      )
    )

    expect(rows.started).toBe('Oct 17')
  })

  it('leaves out the end while the server names none', () => {
    const rows = byId(freeApiCardRows(activeStatus({ expiresAt: null, daysLeft: null }), t, 'en'))

    expect(rows.ends).toBeUndefined()
  })
})
