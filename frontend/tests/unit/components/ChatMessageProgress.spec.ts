import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatMessage from '@/components/ChatMessage.vue'
import en from '@/i18n/en.json'
import de from '@/i18n/de.json'
import es from '@/i18n/es.json'
import fr from '@/i18n/fr.json'
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

const processingCopy = (messages: unknown): Record<string, string> =>
  (messages as { processing: Record<string, string> }).processing

// The indicator translates through `useI18n()` (the global test i18n instance
// is English), so the specs compare against the English strings themselves.
const enProcessing = processingCopy(en)
const enTimeline = (en as unknown as { processing: { timeline: Record<string, string> } })
  .processing.timeline
const fill = (template: string, params: Record<string, string | number>) =>
  template.replace(/\{(\w+)\}/g, (_match, name: string) => String(params[name]))

const mountIndicator = (props: Record<string, unknown>) =>
  mount(ChatMessage, {
    ...mountOptions,
    props: {
      role: 'assistant' as const,
      parts: [],
      timestamp: new Date(),
      isStreaming: true,
      ...props,
    },
  })

const indicatorText = (
  processingStatus: string,
  processingMetadata: Record<string, unknown> = {}
): string =>
  mountIndicator({ processingStatus, processingMetadata })
    .get('[data-testid="loading-typing-indicator"]')
    .text()

describe('ChatMessage pre-answer progress indicator', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it.each([
    ['analyzing_prompt', 'analyzingPromptTitle', 'analyzingPromptDesc'],
    ['planning', 'planningTitle', 'planningDesc'],
    ['searching_files', 'searchingFilesTitle', 'searchingFilesDesc'],
    ['checking_memories', 'checkingMemoriesTitle', 'checkingMemoriesDesc'],
  ])('renders its own copy for the %s phase', (status, titleKey, descKey) => {
    const text = indicatorText(status)

    expect(text).toContain(enProcessing[titleKey])
    expect(text).toContain(enProcessing[descKey])
  })

  it('does not fall back to the generic generating copy', () => {
    expect(indicatorText('planning')).not.toContain(enProcessing.generatingTitle)
  })

  // "Make the car blue" now edits the picture from earlier in the conversation
  // instead of drawing a new one. Saying so is what tells the user the edit
  // actually landed on the file they meant.
  it('names the image being edited when the backend reports one', () => {
    const text = indicatorText('editing', { edit_source_name: 'car-sunset.png' })

    expect(text).toContain(enProcessing.editingImageTitle)
    expect(text).toContain(fill(enProcessing.editingImageNamed, { filename: 'car-sunset.png' }))
  })

  it('falls back to generic editing copy without a filename', () => {
    const text = indicatorText('editing')

    expect(text).toContain(enProcessing.editingImageTitle)
    expect(text).toContain(enProcessing.editingImageDesc)
  })

  it('names the model the request was sent to', () => {
    const text = indicatorText('generating', { model_name: 'Grok 4', stage: 'request_sent' })

    expect(text).toContain(fill(enTimeline.sendingTo, { model: 'Grok 4' }))
    expect(text).toContain(fill(enTimeline.waitingFor, { model: 'Grok 4' }))
  })

  it('shows the initial waiting row when the bubble has only an empty text part', () => {
    const wrapper = mountIndicator({
      parts: [{ type: 'text', content: '' }],
      processingStatus: '',
    })

    expect(wrapper.find('[data-testid="loading-initial-indicator"]').exists()).toBe(true)
  })

  it('shows a generic waiting row before the backend narrates anything', () => {
    const text = indicatorText('started')

    expect(text).toContain(enProcessing.startedTitle)
  })
})

describe('ChatMessage progress timeline', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  const step = (
    id: number,
    key: string,
    status: string,
    state: 'active' | 'done',
    metadata: Record<string, unknown> = {},
    afterAnswer = false
  ) => ({
    id,
    key,
    status,
    state,
    metadata,
    startedAt: 1_000 + id * 1_000,
    endedAt: state === 'done' ? 1_000 + id * 1_000 + 1_500 : undefined,
    afterAnswer,
  })

  it('lists finished steps with their duration above the active one', () => {
    const wrapper = mountIndicator({
      processingStatus: 'generating',
      processingSteps: [
        step(1, 'understand', 'classified', 'done', { intent: 'chat', web_search: true }),
        step(2, 'web', 'search_complete', 'done', { results_count: 8 }),
        step(3, 'generate', 'generating', 'active', { model_name: 'Grok 4' }),
      ],
      processingModel: { name: 'Grok 4' },
    })

    const done = wrapper.get('[data-testid="timeline-done-steps"]').text()
    expect(done).toContain(enTimeline.understoodDone)
    expect(done).toContain(enProcessing.searchCompleteTitle)
    expect(done).toContain('8 ' + enProcessing.results)
    expect(done).toContain('1.5s')

    const active = wrapper.get('[data-testid="timeline-active-step"]').text()
    expect(active).toContain(fill(enTimeline.sendingTo, { model: 'Grok 4' }))
  })

  it('shows only the post-answer pass once the answer is on screen', () => {
    const wrapper = mountIndicator({
      parts: [{ type: 'text', content: 'Hello there' }],
      processingStatus: 'analyzing_memories',
      processingSteps: [
        step(1, 'understand', 'classified', 'done'),
        step(2, 'generate', 'generated', 'done', { model_name: 'M' }),
        step(3, 'memories_after', 'analyzing_memories', 'active', {}, true),
      ],
    })

    const indicator = wrapper.get('[data-testid="loading-typing-indicator"]')
    expect(indicator.find('[data-testid="timeline-done-steps"]').exists()).toBe(false)
    expect(indicator.text()).toContain(enProcessing.analyzingMemoriesTitle)
  })

  it('folds the finished steps into a summary line while the answer streams', () => {
    const wrapper = mountIndicator({
      parts: [{ type: 'text', content: 'Hello there' }],
      processingStatus: '',
      processingSteps: [
        step(1, 'understand', 'classified', 'done'),
        step(2, 'generate', 'generated', 'done', { model_name: 'M' }),
      ],
      processingModel: { name: 'M' },
    })

    expect(wrapper.find('[data-testid="loading-typing-indicator"]').exists()).toBe(false)
    const summary = wrapper.get('[data-testid="processing-timeline-summary"]')
    expect(summary.text()).toContain('2 steps')
    expect(summary.text()).toContain('M')
  })

  it('keeps the folded summary on the finished message after the stream ends', () => {
    const wrapper = mountIndicator({
      isStreaming: false,
      parts: [{ type: 'text', content: 'Hello there' }],
      processingStatus: '',
      processingSteps: [
        step(1, 'understand', 'classified', 'done'),
        step(2, 'generate', 'generated', 'done', { model_name: 'M' }),
      ],
      processingModel: { name: 'M' },
    })

    expect(wrapper.find('[data-testid="loading-typing-indicator"]').exists()).toBe(false)
    const summary = wrapper.get('[data-testid="processing-timeline-summary"]')
    expect(summary.text()).toContain('2 steps')
    expect(summary.text()).toContain('M')
  })
})

describe('pre-answer progress copy', () => {
  // A missing key silently falls back to English, which reads as a bug in the
  // other three UI languages.
  it.each([
    ['de', de],
    ['es', es],
    ['fr', fr],
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
