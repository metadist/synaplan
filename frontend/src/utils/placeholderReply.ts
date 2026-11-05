import type { TaskCard } from '@/stores/taskCard'

/**
 * Body text that is not a real answer: the assembler fallback, or a media
 * generating/generated token. The task card (DAG) or media-job banner
 * (single-task) is the surface — this copy must not sit next to it.
 *
 * Keep the fallback strings in lockstep with
 * `App\Service\Message\PlaceholderReply::FALLBACK_TEXT`.
 */
export const DEGRADED_FALLBACK_REPLIES = [
  "I couldn't fully complete that request.",
  'Ich konnte diese Anfrage leider nicht vollständig abschließen.',
  'No pude completar esa solicitud por completo.',
  "Je n'ai pas pu terminer cette demande entièrement.",
  'Bu isteği tamamen tamamlayamadım.',
] as const

export const MEDIA_GENERATING_TOKENS = [
  '__IMAGE_GENERATING__',
  '__VIDEO_GENERATING__',
  '__AUDIO_GENERATING__',
] as const

export const MEDIA_GENERATED_TOKENS = [
  '__IMAGE_GENERATED__',
  '__VIDEO_GENERATED__',
  '__AUDIO_GENERATED__',
] as const

const MEDIA_CARD_KINDS = new Set(['image', 'video', 'audio'])

function isMediaCard(card: TaskCard): boolean {
  return MEDIA_CARD_KINDS.has(card.kind)
}

function mediaCardIsLiveOrDone(card: TaskCard): boolean {
  return 'running' === card.state || 'pending' === card.state || 'done' === card.state
}

export function shouldHidePlaceholderReply(
  text: string,
  options: { hasMediaJob: boolean; cards: readonly TaskCard[] }
): boolean {
  const trimmed = text.trim()
  if ('' === trimmed) {
    return false
  }

  const isGenerating = (MEDIA_GENERATING_TOKENS as readonly string[]).includes(trimmed)
  const isGenerated = (MEDIA_GENERATED_TOKENS as readonly string[]).includes(trimmed)
  const isFallback = (DEGRADED_FALLBACK_REPLIES as readonly string[]).includes(trimmed)
  const mediaCards = options.cards.filter(isMediaCard)

  if (isGenerating && (options.hasMediaJob || mediaCards.length > 0)) {
    return true
  }
  if (isGenerated && mediaCards.length > 0) {
    return true
  }
  if (isFallback && mediaCards.some(mediaCardIsLiveOrDone)) {
    return true
  }

  return false
}
