import { describe, expect, it } from 'vitest'
import {
  ACCOUNT_INTRO_WINDOW_MS,
  parseAccountCreatedAt,
  shouldShowSelfAwareEmptyHint,
  type EmptyLandingActionsInput,
} from '@/utils/emptyLandingActions'

const createdMs = Date.UTC(2026, 9, 1, 12, 0, 0)

const openLanding: EmptyLandingActionsInput = {
  incognito: false,
  hasPinnedAssistant: false,
  starterPromptCount: 0,
  canCompose: true,
  needsProviderSetup: false,
  hasAccount: true,
  accountCreatedAt: '2026-10-01 12:00:00',
  nowMs: createdMs + 60_000,
}

describe('shouldShowSelfAwareEmptyHint', () => {
  it('shows on the unpinned start page while the account is in its first week', () => {
    expect(shouldShowSelfAwareEmptyHint(openLanding)).toBe(true)
  })

  it('shows for a guest, who has no account age yet', () => {
    expect(
      shouldShowSelfAwareEmptyHint({
        ...openLanding,
        hasAccount: false,
        accountCreatedAt: null,
      })
    ).toBe(true)
  })

  it('shows when a pinned assistant has no starters so the empty state keeps an action', () => {
    expect(
      shouldShowSelfAwareEmptyHint({
        ...openLanding,
        hasPinnedAssistant: true,
        starterPromptCount: 0,
      })
    ).toBe(true)
  })

  it('hides when the pinned assistant already has starters', () => {
    expect(
      shouldShowSelfAwareEmptyHint({
        ...openLanding,
        hasPinnedAssistant: true,
        starterPromptCount: 2,
      })
    ).toBe(false)
  })

  it('hides when the composer cannot submit', () => {
    expect(shouldShowSelfAwareEmptyHint({ ...openLanding, canCompose: false })).toBe(false)
  })

  it('hides when chat still needs a provider', () => {
    expect(shouldShowSelfAwareEmptyHint({ ...openLanding, needsProviderSetup: true })).toBe(false)
  })

  it('hides in incognito', () => {
    expect(shouldShowSelfAwareEmptyHint({ ...openLanding, incognito: true })).toBe(false)
  })

  it('stays up until the week is over, then stays off', () => {
    expect(
      shouldShowSelfAwareEmptyHint({
        ...openLanding,
        nowMs: createdMs + ACCOUNT_INTRO_WINDOW_MS - 1,
      })
    ).toBe(true)
    expect(
      shouldShowSelfAwareEmptyHint({
        ...openLanding,
        nowMs: createdMs + ACCOUNT_INTRO_WINDOW_MS,
      })
    ).toBe(false)
  })

  it('hides when a signed-in account has no readable creation time', () => {
    expect(
      shouldShowSelfAwareEmptyHint({
        ...openLanding,
        accountCreatedAt: null,
      })
    ).toBe(false)
    expect(
      shouldShowSelfAwareEmptyHint({
        ...openLanding,
        accountCreatedAt: 'yesterday',
      })
    ).toBe(false)
  })

  it('still shows when the stored creation time is slightly ahead of this clock', () => {
    expect(
      shouldShowSelfAwareEmptyHint({
        ...openLanding,
        nowMs: createdMs - 60_000,
      })
    ).toBe(true)
  })
})

describe('parseAccountCreatedAt', () => {
  it('reads the formats the account row actually stores', () => {
    expect(parseAccountCreatedAt('2026-10-01 12:00:00')).toBe(createdMs)
    expect(parseAccountCreatedAt('2026-10-01T12:00:00')).toBe(createdMs)
    expect(parseAccountCreatedAt('20261001120000')).toBe(createdMs)
  })

  it('rejects a calendar day that does not exist', () => {
    expect(parseAccountCreatedAt('2026-02-31 12:00:00')).toBeNull()
  })
})
