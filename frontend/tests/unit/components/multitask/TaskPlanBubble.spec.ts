import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import TaskPlanBubble from '@/components/multitask/TaskPlanBubble.vue'
import type { TaskPlanState } from '@/stores/history'

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
    mocks: { $t: (key: string, fallback?: string) => fallback ?? key },
    stubs: { Icon: true, MessageText: messageTextStub },
  },
}

const plan = (cards: TaskPlanState['cards']): TaskPlanState => ({
  active: true,
  replyNode: 'n4',
  cards,
})

describe('TaskPlanBubble', () => {
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
})
