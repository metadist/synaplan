import { describe, expect, it } from 'vitest'
import { loadAllMessages } from '@/i18n/loadAllMessages'
import { EMPTY_GREETING_COUNT, pickEmptyGreetingKey } from '@/utils/emptyGreeting'

describe('pickEmptyGreetingKey', () => {
  it('stays inside the greeting list', () => {
    expect(pickEmptyGreetingKey(0)).toBe(1)
    expect(pickEmptyGreetingKey(0.999)).toBe(EMPTY_GREETING_COUNT)
    expect(pickEmptyGreetingKey(1)).toBe(EMPTY_GREETING_COUNT)
  })

  it('has the same lines in every locale', () => {
    for (const locale of ['en', 'de', 'es', 'fr', 'tr']) {
      const messages = loadAllMessages(locale)
      const greetings = (messages.companionLinks as { greetings: Record<string, string> }).greetings
      expect(
        Object.keys(greetings)
          .map(Number)
          .sort((a, b) => a - b)
      ).toEqual(Array.from({ length: EMPTY_GREETING_COUNT }, (_, index) => index + 1))
      for (const line of Object.values(greetings)) {
        expect(line.trim().length).toBeGreaterThan(0)
      }
    }
  })
})
