import { describe, expect, it } from 'vitest'
import { selectAnnouncement } from '@/composables/useAnnouncements'
import { announcements, type Announcement } from '@/data/announcements'

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

const anyVisitor = { iosAppUrl: 'https://apps.apple.com/app/id1', isNativeApp: false }

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
  const iosApp = announcements.find((entry) => 'ios-app-launch' === entry.id)

  it('contains the iPhone announcement', () => {
    expect(iosApp).toBeDefined()
  })

  it('never advertises an app the operator has not published', () => {
    expect(iosApp?.applies({ iosAppUrl: '', isNativeApp: false })).toBe(false)
  })

  it('does not advertise the app to someone already using it', () => {
    expect(
      iosApp?.applies({ iosAppUrl: 'https://apps.apple.com/app/id1', isNativeApp: true })
    ).toBe(false)
  })

  it('reaches web visitors of an instance that has an app', () => {
    expect(
      iosApp?.applies({ iosAppUrl: 'https://apps.apple.com/app/id1', isNativeApp: false })
    ).toBe(true)
  })

  it('tags the store link so the installs can be attributed', () => {
    expect(
      iosApp?.actionUrl?.({ iosAppUrl: 'https://apps.apple.com/app/id1', isNativeApp: false })
    ).toBe('https://apps.apple.com/app/id1?ct=web-announcement')
  })

  it('keeps a query string the operator already configured', () => {
    expect(
      iosApp?.actionUrl?.({
        iosAppUrl: 'https://apps.apple.com/de/app/id1?l=de',
        isNativeApp: false,
      })
    ).toBe('https://apps.apple.com/de/app/id1?l=de&ct=web-announcement')
  })

  it('gives every entry an id and expiry that the modal can rely on', () => {
    for (const entry of announcements) {
      expect(entry.id).not.toBe('')
      expect(Number.isNaN(Date.parse(`${entry.until}T23:59:59Z`))).toBe(false)
    }
  })
})
