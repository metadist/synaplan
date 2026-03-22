/**
 * Maps classification topic (and optional BMEDIA type for mediamaker) to a coarse
 * media hint for model selection when the assistant message has no image/video/audio parts yet
 * (e.g. generation failed with only error text).
 */
export type MediaGenerationKind = 'image' | 'video' | 'audio'

export function mediaHintFromClassificationTopic(
  effectiveTopic: string | null | undefined,
  originalMediaType: string | null | undefined
): MediaGenerationKind | null {
  const t = (effectiveTopic ?? '').toLowerCase()
  if (t === 'text2pic' || t === 'pic2pic') {
    return 'image'
  }
  if (t === 'text2vid') {
    return 'video'
  }
  if (t === 'text2sound') {
    return 'audio'
  }
  if (t === 'mediamaker' && originalMediaType) {
    const m = originalMediaType.toLowerCase()
    if (m === 'image') {
      return 'image'
    }
    if (m === 'video') {
      return 'video'
    }
    if (m === 'audio') {
      return 'audio'
    }
  }
  return null
}
