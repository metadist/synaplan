import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { ref } from 'vue'
import { useComposerStop } from '@/composables/useComposerStop'
import { emptyProgress } from '@/services/chat/stream/handlers/research'
import { useResearchStore } from '@/stores/research'

vi.mock('@/services/api/researchApi', () => ({
  fetchResearchCapabilities: vi.fn(),
  startResearch: vi.fn(),
  fetchResearchRun: vi.fn(),
  fetchResearchEvents: vi.fn(async () => ''),
  answerNowResearch: vi.fn(),
  cancelResearch: vi.fn(),
}))

describe('useComposerStop', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  function slot() {
    return useComposerStop({
      streaming: ref(false),
      guest: ref(false),
      hasText: ref(false),
      hasAttachment: ref(false),
      livePress: vi.fn(),
    })
  }

  it('keeps send while nothing is running', () => {
    const composer = slot()
    expect(composer.composerStopping.value).toBe(false)
    expect(composer.showLiveInSendSlot.value).toBe(false)
  })

  it('makes the send button the stop control while research is going', () => {
    const research = useResearchStore()
    research.progress = emptyProgress('rr_0123456789abcdef', 7, 'Which visa?')
    const composer = slot()
    const send = vi.fn()
    const stopStream = vi.fn()

    expect(composer.composerStopping.value).toBe(true)
    expect(composer.showLiveInSendSlot.value).toBe(false)

    composer.onSendSlot(send, stopStream)

    expect(send).not.toHaveBeenCalled()
    expect(stopStream).not.toHaveBeenCalled()
  })
})
