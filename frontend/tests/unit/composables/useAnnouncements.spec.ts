import { describe, expect, it } from 'vitest'
import { selectAnnouncement } from '@/composables/useAnnouncements'
import { announcements, type Announcement, type AnnouncementContext } from '@/data/announcements'

const NOW = Date.parse('2026-08-17T10:00:00Z')

function announcement(overrides: Partial<Announcement> = {}): Announcement {
  return {
    id: 'test',
    i18nKey: 'announcements.test',
    until: '2099-01-01',
    applies: () => true,
    ...overrides,
  }
}

function visitor(overrides: Partial<AnnouncementContext> = {}): AnnouncementContext {
  return {
    iosAppUrl: 'https://apps.apple.com/app/id1',
    androidAppUrl: 'https://play.google.com/store/apps/details?id=com.synaplan.app',
    isNativeApp: false,
    deviceOs: 'other',
    ...overrides,
  }
}

const anyVisitor = visitor()

describe('selectAnnouncement', () => {
  it('offers the first announcement the visitor has not seen', () => {
    const first = announcement({ id: 'first' })
    const second = announcement({ id: 'second' })

    expect(selectAnnouncement([first, second], [], anyVisitor, NOW)).toBe(first)
    expect(selectAnnouncement([first, second], ['first'], anyVisitor, NOW)).toBe(second)
    expect(selectAnnouncement([first, second], ['first', 'second'], anyVisitor, NOW)).toBeNull()
  })

  it('retires an announcement after its last day, even for someone who never saw it', () => {
    const expired = announcement({ until: '2026-08-16' })

    expect(selectAnnouncement([expired], [], anyVisitor, NOW)).toBeNull()
  })

  it('still shows one on its last day', () => {
    const endsToday = announcement({ until: '2026-08-17' })

    expect(selectAnnouncement([endsToday], [], anyVisitor, NOW)).toBe(endsToday)
  })

  it('treats an unparseable date as expired rather than showing it forever', () => {
    const typo = announcement({ until: 'next summer' })

    expect(selectAnnouncement([typo], [], anyVisitor, NOW)).toBeNull()
  })

  it('skips announcements that do not apply to this visitor', () => {
    const irrelevant = announcement({ applies: () => false })

    expect(selectAnnouncement([irrelevant], [], anyVisitor, NOW)).toBeNull()
  })
})

describe('the shipped catalogue', () => {
  const mobileApp = announcements.find((entry) => 'mobile-apps-launch' === entry.id)

  it('contains the mobile app announcement', () => {
    expect(mobileApp).toBeDefined()
  })

  it('never advertises an app the operator has not published', () => {
    expect(mobileApp?.applies(visitor({ iosAppUrl: '', androidAppUrl: '' }))).toBe(false)
  })

  it('reaches an operator who only published the iOS app', () => {
    expect(mobileApp?.applies(visitor({ androidAppUrl: '' }))).toBe(true)
  })

  it('reaches an operator who only published the Android app', () => {
    expect(mobileApp?.applies(visitor({ iosAppUrl: '' }))).toBe(true)
  })

  it('does not advertise the app to someone already using it', () => {
    expect(mobileApp?.applies(visitor({ isNativeApp: true }))).toBe(false)
  })

  it('reaches web visitors of an instance that has an app', () => {
    expect(mobileApp?.applies(visitor())).toBe(true)
  })

  it('offers both stores, App Store first for a desktop or unrecognized visitor', () => {
    const actions = mobileApp?.actions?.(visitor({ deviceOs: 'other' })) ?? []

    expect(actions.map((action) => action.labelKey)).toEqual(['appStore', 'googlePlay'])
  })

  it('leads with Google Play for a visitor on an Android device', () => {
    const actions = mobileApp?.actions?.(visitor({ deviceOs: 'android' })) ?? []

    expect(actions.map((action) => action.labelKey)).toEqual(['googlePlay', 'appStore'])
  })

  it('offers only the store the operator actually published', () => {
    const actions = mobileApp?.actions?.(visitor({ androidAppUrl: '' })) ?? []

    expect(actions.map((action) => action.labelKey)).toEqual(['appStore'])
  })

  it('tags every store link so the installs can be attributed', () => {
    const actions = mobileApp?.actions?.(visitor()) ?? []

    expect(actions.map((action) => action.url)).toEqual([
      'https://apps.apple.com/app/id1?ct=web-announcement',
      'https://play.google.com/store/apps/details?id=com.synaplan.app&ct=web-announcement',
    ])
  })

  it('keeps a query string the operator already configured', () => {
    const actions =
      mobileApp?.actions?.(
        visitor({ iosAppUrl: 'https://apps.apple.com/de/app/id1?l=de', androidAppUrl: '' })
      ) ?? []

    expect(actions[0]?.url).toBe('https://apps.apple.com/de/app/id1?l=de&ct=web-announcement')
  })

  it('keeps a fragment after the query instead of appending past it', () => {
    const actions =
      mobileApp?.actions?.(
        visitor({ iosAppUrl: 'https://apps.apple.com/app/id1#reviews', androidAppUrl: '' })
      ) ?? []

    expect(actions[0]?.url).toBe('https://apps.apple.com/app/id1?ct=web-announcement#reviews')
  })

  it('overwrites rather than duplicates a ct the operator already configured', () => {
    const actions =
      mobileApp?.actions?.(
        visitor({ iosAppUrl: 'https://apps.apple.com/app/id1?ct=operator', androidAppUrl: '' })
      ) ?? []

    expect(actions[0]?.url).toBe('https://apps.apple.com/app/id1?ct=web-announcement')
  })

  it('falls back to plain concatenation for a non-absolute store URL', () => {
    const actions =
      mobileApp?.actions?.(visitor({ iosAppUrl: '/local-app', androidAppUrl: '' })) ?? []

    expect(actions[0]?.url).toBe('/local-app?ct=web-announcement')
  })

  it('gives every entry an id and expiry that the modal can rely on', () => {
    for (const entry of announcements) {
      expect(entry.id).not.toBe('')
      expect(Number.isNaN(Date.parse(`${entry.until}T23:59:59Z`))).toBe(false)
    }
  })
})
