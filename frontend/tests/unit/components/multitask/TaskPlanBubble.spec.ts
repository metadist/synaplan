import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskPlanBubble from '@/components/multitask/TaskPlanBubble.vue'
import type { TaskPlanState } from '@/stores/history'
import { useAiConfigStore } from '@/stores/aiConfig'
import type { AIModel } from '@/types/ai-models'

// `MessageText` is stubbed because the real component pulls in Pinia stores
// and the markdown pipeline; both are tested elsewhere. The stub preserves
// the visible text so the existing `text()` assertions still pass after
// TaskCard switched the body of text-kind cards to render through
// `MessageText` (markdown parity with the legacy chat bubble).
const messageTextStub = {
  name: 'MessageText',
  props: ['content', 'isStreaming', 'readonly'],
  template: '<div data-testid="message-text-stub">{{ content }}</div>',
}

const mountOptions = {
  global: {
    mocks: {
      $t: (key: string, fallback?: string | Record<string, unknown>) =>
        typeof fallback === 'string' ? fallback : key,
    },
    stubs: { Icon: true, MessageText: messageTextStub },
  },
}

const plan = (cards: TaskPlanState['cards']): TaskPlanState => ({
  active: true,
  replyNode: 'n4',
  cards,
})

const model = (id: number, name: string, rating: number): AIModel => ({
  id,
  service: 'test',
  name,
  tag: 'TEXT2PIC',
  providerId: 'model-'.concat(String(id)),
  quality: 1,
  rating,
  priceIn: 0,
  priceOut: 0,
  description: null,
  isSystemModel: true,
  features: [],
})

describe('TaskPlanBubble', () => {
  // TaskCard reads the aiConfig store (retry-with-next-model on failed media
  // cards) — give every mount a fresh Pinia so specs stay isolated.
  beforeEach(() => {
    setActivePinia(createPinia())
  })
  it('renders one card per task node', () => {
    const wrapper = mount(TaskPlanBubble, {
      props: {
        plan: plan([
          { nodeId: 'n1', capability: 'summarize', kind: 'text', state: 'done', text: 'Summary' },
          { nodeId: 'n3', capability: 'text2sound', kind: 'audio', state: 'running' },
        ]),
      },
      ...mountOptions,
    })

    expect(wrapper.find('[data-testid="task-plan"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="task-card-n1"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="task-card-n3"]').exists()).toBe(true)
  })

  it('reflects each card state via data-state', () => {
    const wrapper = mount(TaskPlanBubble, {
      props: {
        plan: plan([
          { nodeId: 'n1', capability: 'image_generation', kind: 'image', state: 'failed' },
        ]),
      },
      ...mountOptions,
    })

    expect(wrapper.find('[data-testid="task-card-n1"]').attributes('data-state')).toBe('failed')
  })

  it('streams text into a text card', () => {
    const wrapper = mount(TaskPlanBubble, {
      props: {
        plan: plan([
          {
            nodeId: 'n2',
            capability: 'summarize',
            kind: 'text',
            state: 'running',
            text: 'Half a sum',
          },
        ]),
      },
      ...mountOptions,
    })

    expect(wrapper.find('[data-testid="task-card-n2"]').text()).toContain('Half a sum')
  })

  it('shows a media skeleton while an image card is running without a file', () => {
    const wrapper = mount(TaskPlanBubble, {
      props: {
        plan: plan([
          { nodeId: 'n1', capability: 'image_generation', kind: 'image', state: 'running' },
        ]),
      },
      ...mountOptions,
    })

    expect(wrapper.find('.task-card__skeleton').exists()).toBe(true)
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('renders the image once the file has arrived', () => {
    const wrapper = mount(TaskPlanBubble, {
      props: {
        plan: plan([
          {
            nodeId: 'n1',
            capability: 'image_generation',
            kind: 'image',
            state: 'done',
            url: 'https://example.com/dog.png',
          },
        ]),
      },
      ...mountOptions,
    })

    expect(wrapper.find('.task-card__skeleton').exists()).toBe(false)
    expect(wrapper.find('img').attributes('src')).toBe('https://example.com/dog.png')
  })

  it('renders an audio player for a resolved audio card', () => {
    const wrapper = mount(TaskPlanBubble, {
      props: {
        plan: plan([
          {
            nodeId: 'n3',
            capability: 'text2sound',
            kind: 'audio',
            state: 'done',
            url: '/api/v1/files/uploads/x.mp3',
          },
        ]),
      },
      ...mountOptions,
    })

    expect(wrapper.find('audio').exists()).toBe(true)
    expect(wrapper.find('audio').attributes('src')).toBe('/api/v1/files/uploads/x.mp3')
  })

  it('shows the specific backend error on a failed card', () => {
    const wrapper = mount(TaskPlanBubble, {
      props: {
        plan: plan([
          {
            nodeId: 'n1',
            capability: 'image_generation',
            kind: 'image',
            state: 'failed',
            error: 'provider 500: quota exceeded',
          },
        ]),
      },
      ...mountOptions,
    })

    expect(wrapper.find('[data-testid="task-card-error"]').text()).toBe(
      'provider 500: quota exceeded'
    )
  })

  it('shows the dependency reason on a skipped card', () => {
    const wrapper = mount(TaskPlanBubble, {
      props: {
        plan: plan([
          {
            nodeId: 'n4',
            capability: 'compose_reply',
            kind: 'text',
            state: 'skipped',
            error: "dependency 'n1' did not complete",
          },
        ]),
      },
      ...mountOptions,
    })

    expect(wrapper.find('[data-testid="task-card-n4"]').text()).toContain(
      "dependency 'n1' did not complete"
    )
  })

  it('offers retry with the next model and bubbles the retryTask event', async () => {
    const aiConfig = useAiConfigStore()
    // Rating-sorted pool: Banana (default, 100) → next is Flux (90).
    aiConfig.models = { TEXT2PIC: [model(190, 'Nano Banana 2', 100), model(191, 'Flux', 90)] }
    aiConfig.defaults = { TEXT2PIC: 190 }

    const wrapper = mount(TaskPlanBubble, {
      props: {
        plan: plan([
          {
            nodeId: 'n1',
            capability: 'image_generation',
            kind: 'image',
            state: 'failed',
            error: 'provider 500',
            prompt: 'a dog in the rain',
          },
        ]),
      },
      ...mountOptions,
    })

    const retry = wrapper.find('[data-testid="task-card-retry"]')
    expect(retry.exists()).toBe(true)

    await retry.trigger('click')

    expect(wrapper.emitted('retryTask')).toEqual([[{ prompt: 'a dog in the rain', modelId: 191 }]])
  })

  it('hides the retry button when no prompt or no models are available', () => {
    // No models seeded in the aiConfig store and no prompt on the card.
    const wrapper = mount(TaskPlanBubble, {
      props: {
        plan: plan([
          { nodeId: 'n1', capability: 'image_generation', kind: 'image', state: 'failed' },
        ]),
      },
      ...mountOptions,
    })

    expect(wrapper.find('[data-testid="task-card-retry"]').exists()).toBe(false)
  })

  it('hides the retry button when the pool only contains the failed model', () => {
    // A single-model pool would "retry" with the very model that just
    // failed — misleading on non-transient failures, so no button.
    const aiConfig = useAiConfigStore()
    aiConfig.models = { TEXT2PIC: [model(190, 'Nano Banana 2', 100)] }
    aiConfig.defaults = { TEXT2PIC: 190 }

    const wrapper = mount(TaskPlanBubble, {
      props: {
        plan: plan([
          {
            nodeId: 'n1',
            capability: 'image_generation',
            kind: 'image',
            state: 'failed',
            error: 'provider 500',
            prompt: 'a dog in the rain',
          },
        ]),
      },
      ...mountOptions,
    })

    expect(wrapper.find('[data-testid="task-card-retry"]').exists()).toBe(false)
  })

  it('does not offer a retry on failed non-media cards', () => {
    const aiConfig = useAiConfigStore()
    aiConfig.models = { TEXT2PIC: [model(190, 'Nano Banana 2', 100)] }
    aiConfig.defaults = { TEXT2PIC: 190 }

    const wrapper = mount(TaskPlanBubble, {
      props: {
        plan: plan([
          {
            nodeId: 'n2',
            capability: 'summarize',
            kind: 'text',
            state: 'failed',
            error: 'model error',
            prompt: 'summarize this',
          },
        ]),
      },
      ...mountOptions,
    })

    expect(wrapper.find('[data-testid="task-card-retry"]').exists()).toBe(false)
  })

  it('renders an email card for the email_me capability', () => {
    const wrapper = mount(TaskPlanBubble, {
      props: {
        plan: plan([
          {
            nodeId: 'n5',
            capability: 'email_me',
            kind: 'email',
            state: 'done',
            text: 'Sent to a***@example.com',
          },
        ]),
      },
      ...mountOptions,
    })

    const card = wrapper.find('[data-testid="task-card-n5"]')
    expect(card.exists()).toBe(true)
    expect(card.text()).toContain('Sent to a***@example.com')
  })
})
