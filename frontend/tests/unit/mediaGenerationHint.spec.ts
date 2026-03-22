import { describe, expect, it } from 'vitest'
import { mediaHintFromClassificationTopic } from '@/utils/mediaGenerationHint'

describe('mediaHintFromClassificationTopic', () => {
  it('maps text2pic to image', () => {
    expect(mediaHintFromClassificationTopic('text2pic', null)).toBe('image')
  })

  it('maps text2vid to video', () => {
    expect(mediaHintFromClassificationTopic('TEXT2VID', null)).toBe('video')
  })

  it('maps text2sound to audio', () => {
    expect(mediaHintFromClassificationTopic('text2sound', null)).toBe('audio')
  })

  it('maps mediamaker using originalMediaType', () => {
    expect(mediaHintFromClassificationTopic('mediamaker', 'video')).toBe('video')
    expect(mediaHintFromClassificationTopic('mediamaker', 'IMAGE')).toBe('image')
  })

  it('returns null when topic is unknown or mediamaker without media type', () => {
    expect(mediaHintFromClassificationTopic('general', null)).toBeNull()
    expect(mediaHintFromClassificationTopic('mediamaker', null)).toBeNull()
    expect(mediaHintFromClassificationTopic('mediamaker', '')).toBeNull()
  })
})
