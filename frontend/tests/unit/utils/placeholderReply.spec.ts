import { describe, expect, it } from 'vitest'
import type { TaskCard } from '@/stores/taskCard'
import { shouldHidePlaceholderReply, DEGRADED_FALLBACK_REPLIES } from '@/utils/placeholderReply'

const imageCard = (state: TaskCard['state'], url?: string): TaskCard => ({
  nodeId: 'n1',
  capability: 'image_generation',
  kind: 'image',
  state,
  ...(url ? { url } : {}),
})

describe('shouldHidePlaceholderReply', () => {
  it('hides generating tokens next to a single-task media job', () => {
    expect(
      shouldHidePlaceholderReply('__IMAGE_GENERATING__', { hasMediaJob: true, cards: [] })
    ).toBe(true)
  })

  it('hides generating and generated tokens next to a media task card', () => {
    const cards = [imageCard('running')]
    expect(shouldHidePlaceholderReply('__IMAGE_GENERATING__', { hasMediaJob: false, cards })).toBe(
      true
    )
    expect(
      shouldHidePlaceholderReply('__IMAGE_GENERATED__', {
        hasMediaJob: false,
        cards: [imageCard('done', '/pic.png')],
      })
    ).toBe(true)
  })

  it('keeps generated tokens on a single-task turn without a card', () => {
    expect(
      shouldHidePlaceholderReply('__IMAGE_GENERATED__', { hasMediaJob: true, cards: [] })
    ).toBe(false)
  })

  it('hides the degraded fallback while a media card is running or done', () => {
    for (const fallback of DEGRADED_FALLBACK_REPLIES) {
      expect(
        shouldHidePlaceholderReply(fallback, { hasMediaJob: false, cards: [imageCard('running')] })
      ).toBe(true)
      expect(
        shouldHidePlaceholderReply(fallback, {
          hasMediaJob: false,
          cards: [imageCard('done', '/pic.png')],
        })
      ).toBe(true)
    }
  })

  it('keeps the degraded fallback when media cards all failed', () => {
    expect(
      shouldHidePlaceholderReply("I couldn't fully complete that request.", {
        hasMediaJob: false,
        cards: [imageCard('failed')],
      })
    ).toBe(false)
  })

  it('keeps real prose next to a media card', () => {
    expect(
      shouldHidePlaceholderReply('Here is your parrot.', {
        hasMediaJob: false,
        cards: [imageCard('done', '/pic.png')],
      })
    ).toBe(false)
  })
})
