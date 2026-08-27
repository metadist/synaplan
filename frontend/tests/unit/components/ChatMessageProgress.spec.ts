import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatMessage from '@/components/ChatMessage.vue'
import en from '@/i18n/en.json'
import de from '@/i18n/de.json'
import es from '@/i18n/es.json'
import tr from '@/i18n/tr.json'

// The backend now narrates the phases that run before the first token, so the
// user is not left staring at "Generating response…" while the pipeline plans,
// searches the knowledge base and looks up memories. These specs pin that each
// backend status reaches the indicator with its own copy.

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), resolve: () => ({ href: '#' }) }),
}))

const mountOptions = {
  global: {
    mocks: {
      $t: (key: string) => key,
    },
    stubs: {
      RouterLink: true,
      Icon: true,
      MessagePart: true,
      MessageMemories: true,
      MessageFeedbacks: true,
      ServiceIcon: true,
      ModelCostBadge: true,
      ToolBadge: true,
      TaskPlanBubble: true,
      MediaJobStatus: true,
      ExternalLinkWarning: true,
    },
  },
}

const indicatorText = (
  processingStatus: string,
  processingMetadata: Record<string, unknown> = {}
): string =>
  mount(ChatMessage, {
    ...mountOptions,
    props: {
      role: 'assistant' as const,
      parts: [],
      timestamp: new Date(),
      isStreaming: true,
      processingStatus,
      processingMetadata,
    },
  })
    .get('[data-testid="loading-typing-indicator"]')
    .text()

describe('ChatMessage pre-answer progress indicator', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it.each([
    ['analyzing_prompt', 'processing.analyzingPromptTitle', 'processing.analyzingPromptDesc'],
    ['planning', 'processing.planningTitle', 'processing.planningDesc'],
    ['searching_files', 'processing.searchingFilesTitle', 'processing.searchingFilesDesc'],
    ['checking_memories', 'processing.checkingMemoriesTitle', 'processing.checkingMemoriesDesc'],
  ])('renders its own copy for the %s phase', (status, titleKey, descKey) => {
    const text = indicatorText(status)

    expect(text).toContain(titleKey)
    expect(text).toContain(descKey)
  })

  it('does not fall back to the generic generating copy', () => {
    expect(indicatorText('planning')).not.toContain('processing.generatingTitle')
  })

  // "Make the car blue" now edits the picture from earlier in the conversation
  // instead of drawing a new one. Saying so is what tells the user the edit
  // actually landed on the file they meant.
  it('names the image being edited when the backend reports one', () => {
    const text = indicatorText('editing', { edit_source_name: 'car-sunset.png' })

    expect(text).toContain('processing.editingImageTitle')
    expect(text).toContain('processing.editingImageNamed')
  })

  it('falls back to generic editing copy without a filename', () => {
    const text = indicatorText('editing')

    expect(text).toContain('processing.editingImageTitle')
    expect(text).toContain('processing.editingImageDesc')
  })
})

const processingCopy = (messages: unknown): Record<string, string> =>
  (messages as { processing: Record<string, string> }).processing

describe('pre-answer progress copy', () => {
  // A missing key silently falls back to English, which reads as a bug in the
  // other three UI languages.
  it.each([
    ['de', de],
    ['es', es],
    ['tr', tr],
  ])('is translated in %s', (_locale, messages) => {
    const english = processingCopy(en)
    const keys = Object.keys(english).filter(
      (key) =>
        key.startsWith('analyzingPrompt') ||
        key.startsWith('planning') ||
        key.startsWith('searchingFiles') ||
        key.startsWith('checkingMemories') ||
        key.startsWith('editingImage')
    )
    const locale = processingCopy(messages)

    expect(keys).toHaveLength(11)
    for (const key of keys) {
      expect(locale[key], `missing processing.${key}`).toBeTruthy()
      expect(locale[key], `processing.${key} is still the English string`).not.toBe(english[key])
    }
  })
})
