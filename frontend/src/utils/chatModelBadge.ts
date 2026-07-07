/**
 * Pure helpers for the chat-message model badges.
 *
 * Extracted from `ChatMessage.vue` so the label/icon decision is unit-
 * testable in isolation — see issue #583, where the chat badge was
 * relabelled as "Audio Model" for voice replies even though the
 * displayed identifier was still the chat LLM.
 */
/**
 * Wider than `MediaGenerationKind` from `mediaGenerationHint` because
 * the chat-message component also distinguishes 'vision' for image
 * *interpretation* (Pic2Text) — that branch never originates from
 * classification topic alone.
 */
export type ChatBadgeMediaHint = 'vision' | 'image' | 'video' | 'audio' | null

export type ChatBadgeLabel =
  'Chat Model' | 'Vision Model' | 'Image Model' | 'Video Model' | 'Audio Model' | 'Analyze Model'

export type ChatBadgeIcon =
  'mdi:chat' | 'mdi:eye' | 'mdi:image' | 'mdi:video' | 'mdi:music' | 'mdi:file-search'

/**
 * Decide which label to render under the *chat* badge.
 *
 * Voice-reply special case: when `aiModels.audio` is present (i.e. a
 * separate TTS pipeline produced the audio), the chat row still
 * describes the LLM that authored the *text*. Keeping "Chat Model"
 * here lets the audio engine own the "Audio Model" badge instead.
 */
export function chatBadgeLabel(
  mediaHint: ChatBadgeMediaHint,
  hasAudioModel: boolean,
  isFileAnalysis: boolean
): ChatBadgeLabel {
  if (isFileAnalysis) return 'Analyze Model'
  switch (mediaHint) {
    case 'vision':
      return 'Vision Model'
    case 'image':
      return 'Image Model'
    case 'video':
      return 'Video Model'
    case 'audio':
      return hasAudioModel ? 'Chat Model' : 'Audio Model'
    default:
      return 'Chat Model'
  }
}

/** Icon that mirrors {@link chatBadgeLabel}. */
export function chatBadgeIcon(
  mediaHint: ChatBadgeMediaHint,
  hasAudioModel: boolean,
  isFileAnalysis: boolean
): ChatBadgeIcon {
  if (isFileAnalysis) return 'mdi:file-search'
  switch (mediaHint) {
    case 'vision':
      return 'mdi:eye'
    case 'image':
      return 'mdi:image'
    case 'video':
      return 'mdi:video'
    case 'audio':
      return hasAudioModel ? 'mdi:chat' : 'mdi:music'
    default:
      return 'mdi:chat'
  }
}
