import { describe, it, expect } from 'vitest'
import type { ChatBadgeMediaHint } from '@/utils/chatModelBadge'
import { chatBadgeIcon, chatBadgeLabel } from '@/utils/chatModelBadge'

const hint = (h: ChatBadgeMediaHint): ChatBadgeMediaHint => h

describe('chatModelBadge', () => {
  describe('chatBadgeLabel', () => {
    it("returns 'Chat Model' when no media hint is set", () => {
      expect(chatBadgeLabel(null, false, false)).toBe('Chat Model')
    })

    it("returns 'Vision Model' when interpreting a vision response", () => {
      expect(chatBadgeLabel(hint('vision'), false, false)).toBe('Vision Model')
    })

    it("returns 'Image Model' for image generation", () => {
      expect(chatBadgeLabel('image', false, false)).toBe('Image Model')
    })

    it("returns 'Video Model' for video generation", () => {
      expect(chatBadgeLabel('video', false, false)).toBe('Video Model')
    })

    it("returns 'Audio Model' when the chat handler itself produced audio", () => {
      // Legacy text2sound flow where the chat row IS the audio model.
      expect(chatBadgeLabel('audio', false, false)).toBe('Audio Model')
    })

    it("returns 'Chat Model' for voice replies (audio + separate TTS)", () => {
      // Regression for #583: when a separate TTS pipeline produced
      // the voice reply, the chat row must NOT inherit the 'Audio
      // Model' label — the LLM is still the chat model.
      expect(chatBadgeLabel('audio', true, false)).toBe('Chat Model')
    })

    it("returns 'Analyze Model' for file-analysis responses (highest priority)", () => {
      expect(chatBadgeLabel('audio', true, true)).toBe('Analyze Model')
      expect(chatBadgeLabel(null, false, true)).toBe('Analyze Model')
    })
  })

  describe('chatBadgeIcon', () => {
    it('mirrors the label decision — icon never disagrees with the text', () => {
      expect(chatBadgeIcon(null, false, false)).toBe('mdi:chat')
      expect(chatBadgeIcon(hint('vision'), false, false)).toBe('mdi:eye')
      expect(chatBadgeIcon('image', false, false)).toBe('mdi:image')
      expect(chatBadgeIcon('video', false, false)).toBe('mdi:video')
      expect(chatBadgeIcon('audio', false, false)).toBe('mdi:music')
    })

    it('uses the chat icon for voice replies with a separate TTS model', () => {
      // Same #583 nuance — icon follows the label rule.
      expect(chatBadgeIcon('audio', true, false)).toBe('mdi:chat')
    })

    it('uses the analyze icon for file-analysis responses regardless of media hint', () => {
      expect(chatBadgeIcon('audio', true, true)).toBe('mdi:file-search')
      expect(chatBadgeIcon('image', false, true)).toBe('mdi:file-search')
    })
  })
})
