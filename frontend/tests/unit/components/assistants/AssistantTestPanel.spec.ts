import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import AssistantTestPanel from '@/components/assistants/AssistantTestPanel.vue'
import { emptyAgentDraft } from '@/services/api/agentsApi'
import { useAgentsStore } from '@/stores/agents'
import { useAuthStore } from '@/stores/auth'
import { chatApi } from '@/services/api/chatApi'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'

const en = loadAllMessages('en')

vi.mock('@/services/api/chatApi', () => ({
  chatApi: {
    streamMessage: vi.fn().mockReturnValue(() => undefined),
  },
}))

vi.mock('@/services/api/agentsApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/agentsApi')>()
  return {
    ...actual,
    agentsApi: {
      gallery: vi.fn(),
      get: vi.fn(),
      create: vi.fn(),
      update: vi.fn(),
      clone: vi.fn(),
      remove: vi.fn(),
      list: vi.fn(),
    },
  }
})

function mountPanel(attach = false) {
  setActivePinia(createPinia())
  const store = useAgentsStore()
  store.current = {
    id: 7,
    slug: 'contract-review',
    name: 'Contract review',
    description: null,
    icon: '',
    status: 'draft',
    promptId: 9,
    parentId: null,
    source: 'manual',
    routable: false,
    publishedVersionId: null,
    draft: emptyAgentDraft(),
    createdAt: 1,
    updatedAt: 1,
  }
  const auth = useAuthStore()
  auth.user = { id: 1, email: 'ada@test.com', level: 'PRO', isAdmin: false } as never
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: asI18nSchema(en) } })
  return mount(AssistantTestPanel, {
    attachTo: attach ? document.body : undefined,
    global: {
      plugins: [i18n],
      stubs: { Icon: true },
    },
  })
}

describe('AssistantTestPanel', () => {
  it('posts draft: true with the agent id', async () => {
    const wrapper = mountPanel()
    await wrapper.get('[data-testid="input-test-message"]').setValue('hello draft')
    await wrapper.get('form').trigger('submit')

    expect(chatApi.streamMessage).toHaveBeenCalledWith(
      expect.objectContaining({
        agentId: 7,
        draft: true,
        incognito: true,
        message: 'hello draft',
      })
    )
  })

  it('keeps the input focused after sending', async () => {
    const wrapper = mountPanel(true)
    const input = wrapper.get('[data-testid="input-test-message"]')
    await input.setValue('hello draft')
    await wrapper.get('form').trigger('submit')

    expect(document.activeElement).toBe(input.element)
    wrapper.unmount()
  })

  it('shows the reply while it streams and keeps it once complete', async () => {
    vi.stubGlobal('matchMedia', () => ({ matches: true }))
    type Update = { status: string; chunk?: string }
    let onUpdate: ((data: Update) => void) | undefined
    vi.mocked(chatApi.streamMessage).mockImplementationOnce((options) => {
      onUpdate = options.onUpdate as (data: Update) => void
      return () => undefined
    })

    const wrapper = mountPanel()
    await wrapper.get('[data-testid="input-test-message"]').setValue('hello draft')
    await wrapper.get('form').trigger('submit')

    onUpdate?.({ status: 'data', chunk: 'Hello, ' })
    onUpdate?.({ status: 'data', chunk: 'world.' })
    await wrapper.vm.$nextTick()
    expect(wrapper.get('[data-testid="text-test-live"]').text()).toBe('Hello, world.')

    onUpdate?.({ status: 'complete' })
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="text-test-live"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="list-test-messages"]').text()).toContain('Hello, world.')

    vi.unstubAllGlobals()
  })
})
