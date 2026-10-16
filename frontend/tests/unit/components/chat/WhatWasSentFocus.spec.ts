import { nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatMessage from '@/components/ChatMessage.vue'

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), resolve: () => ({ href: '#' }) }),
}))

let wrapper: VueWrapper | undefined

afterEach(() => {
  wrapper?.unmount()
  wrapper = undefined
  document.body.style.overflow = ''
})

beforeEach(() => {
  setActivePinia(createPinia())
})

function mountMessage(): VueWrapper {
  wrapper = mount(ChatMessage, {
    attachTo: document.body,
    props: {
      role: 'assistant',
      parts: [{ type: 'text', content: 'Here is your answer.' }],
      timestamp: new Date(),
      providerMessages: [{ role: 'you', text: 'Hello' }],
      canRewrite: true,
    },
    global: {
      stubs: {
        Teleport: { template: '<div><slot /></div>' },
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
  })
  return wrapper
}

async function openDialog(view: VueWrapper): Promise<void> {
  await view.get('[data-testid="btn-message-info"]').trigger('click')
  await view.get('[data-testid="btn-what-was-sent"]').trigger('click')
  await nextTick()
}

describe('What was sent focus', () => {
  it('returns focus to the message info button when the dialog is closed', async () => {
    const view = mountMessage()
    await openDialog(view)

    await view.get('[data-testid="btn-what-was-sent-close"]').trigger('click')
    await nextTick()

    expect(document.activeElement).toBe(view.get('[data-testid="btn-message-info"]').element)
    expect(view.find('[data-testid="dialog-what-was-sent"]').exists()).toBe(false)
  })

  it('returns focus to the message info button when Escape closes the dialog', async () => {
    const view = mountMessage()
    await openDialog(view)

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await nextTick()

    expect(document.activeElement).toBe(view.get('[data-testid="btn-message-info"]').element)
    expect(view.find('[data-testid="dialog-what-was-sent"]').exists()).toBe(false)
  })
})
