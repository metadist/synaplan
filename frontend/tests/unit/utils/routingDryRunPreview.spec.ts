import { describe, it, expect } from 'vitest'
import {
  buildDryRunPreview,
  topicToUseCaseId,
  isRoutingExcludedCanonicalTopic,
  canonicalToEditableRoutingTopic,
} from '@/utils/routingDryRunPreview'

describe('routingDryRunPreview', () => {
  it('maps coding topic to text_chat use case', () => {
    expect(topicToUseCaseId('coding', 'general')).toBe('text_chat')
    expect(isRoutingExcludedCanonicalTopic('general')).toBe(true)
    expect(isRoutingExcludedCanonicalTopic('general-chat')).toBe(false)
    expect(canonicalToEditableRoutingTopic('general')).toBe('general-chat')
    expect(canonicalToEditableRoutingTopic('mediamaker')).toBe('image-generation')
  })

  it('detects poem + read aloud from sorter steps', () => {
    const preview = buildDryRunPreview('Write a poem and read it aloud', 'general-chat', 'general', {
      steps: [
        { id: 'write', capability: 'CHAT' },
        { id: 'speak', capability: 'TEXT2SOUND' },
      ],
    })
    expect(preview.isCompound).toBe(true)
    expect(preview.steps).toHaveLength(2)
    expect(preview.steps[0].capability).toBe('CHAT')
    expect(preview.steps[1].capability).toBe('TEXT2SOUND')
  })

  it('builds preview from sorter BSTEPS array', () => {
    const preview = buildDryRunPreview('any query', 'general-chat', null, {
      steps: [
        { id: 'answer', capability: 'CHAT' },
        { id: 'generate', capability: 'TEXT2PIC' },
      ],
    })
    expect(preview.isCompound).toBe(true)
    expect(preview.steps).toHaveLength(2)
  })

  it('detects answer + generate image from sorter classification', () => {
    const preview = buildDryRunPreview(
      'hey beantworte mir die frage: was kostet ein döner in deutschland und generiere ein bild von einem döner',
      'image-generation',
      'mediamaker',
      {
        webSearch: true,
        mediaType: 'image',
        intent: 'image_generation',
      }
    )
    expect(preview.isCompound).toBe(true)
    expect(preview.useCaseId).toBe('text_chat')
    expect(preview.steps).toHaveLength(2)
    expect(preview.steps[0].capability).toBe('CHAT')
    expect(preview.steps[1].capability).toBe('TEXT2PIC')
  })

  it('uses video capability when classification media type is video', () => {
    const preview = buildDryRunPreview(
      'Create a short video clip',
      'video-generation',
      'mediamaker',
      { mediaType: 'video' }
    )
    expect(preview.useCaseId).toBe('media_generation')
    expect(preview.steps[0].capability).toBe('TEXT2VID')
  })
})
