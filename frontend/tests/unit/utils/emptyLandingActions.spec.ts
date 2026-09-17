import { describe, expect, it } from 'vitest'
import {
  shouldShowCompanionLinks,
  shouldShowSelfAwareEmptyHint,
  type EmptyLandingActionsInput,
} from '@/utils/emptyLandingActions'

const openLanding: EmptyLandingActionsInput = {
  incognito: false,
  hasPinnedAssistant: false,
  starterPromptCount: 0,
  canCompose: true,
  needsProviderSetup: false,
}

describe('shouldShowCompanionLinks', () => {
  it('shows on the unpinned start page', () => {
    expect(shouldShowCompanionLinks(openLanding)).toBe(true)
  })

  it('hides in incognito', () => {
    expect(shouldShowCompanionLinks({ ...openLanding, incognito: true })).toBe(false)
  })

  it('hides when an assistant is pinned, even with no starters', () => {
    expect(
      shouldShowCompanionLinks({
        ...openLanding,
        hasPinnedAssistant: true,
        starterPromptCount: 0,
      })
    ).toBe(false)
  })
})

describe('shouldShowSelfAwareEmptyHint', () => {
  it('shows on the unpinned start page when the composer can submit', () => {
    expect(shouldShowSelfAwareEmptyHint(openLanding)).toBe(true)
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
})
