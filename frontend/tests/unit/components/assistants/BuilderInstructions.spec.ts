import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import BuilderInstructions from '@/components/assistants/BuilderInstructions.vue'
import { emptyAgentDraft, type Agent } from '@/services/api/agentsApi'
import { useAgentsStore } from '@/stores/agents'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'

const en = loadAllMessages('en')

const getPromptMock = vi.fn()
const updatePromptMock = vi.fn()

vi.mock('@/services/api/promptsApi', () => ({
  promptsApi: {
    getPrompt: (...args: unknown[]) => getPromptMock(...args),
    updatePrompt: (...args: unknown[]) => updatePromptMock(...args),
  },
}))

vi.mock('@/services/api/agentsApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/agentsApi')>()
  return {
    ...actual,
    agentsApi: { ...actual.agentsApi, update: vi.fn().mockResolvedValue({}) },
  }
})

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn() }),
}))

function agent(overrides: Partial<Agent> = {}): Agent {
  return {
    id: 3,
    slug: 'offer-helper',
    name: 'Offer helper',
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
    ...overrides,
  } as Agent
}

function mountInstructions(current: Agent) {
  setActivePinia(createPinia())
  const store = useAgentsStore()
  store.current = current
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: asI18nSchema(en) } })
  const wrapper = mount(BuilderInstructions, { global: { plugins: [i18n] } })
  return { wrapper, store }
}

describe('BuilderInstructions derived texts', () => {
  beforeEach(() => {
    getPromptMock.mockReset()
    updatePromptMock.mockReset()
    updatePromptMock.mockResolvedValue({})
  })

  it('fills an empty description and greeting from the loaded instructions', async () => {
    getPromptMock.mockResolvedValue({
      prompt: 'Write an offer from the price list and cite every price. Use the current list only.',
    })
    const { store } = mountInstructions(agent())
    await flushPromises()

    expect(store.current?.description).toBe(
      'Write an offer from the price list and cite every price.'
    )
    expect(store.current?.draft?.behaviour.greeting).toBe("Hi, I'm Offer helper. How can I help?")
    expect(store.dirty).toBe(true)
  })

  it('never overwrites texts the user typed', async () => {
    getPromptMock.mockResolvedValue({ prompt: 'Write an offer from the price list.' })
    const draft = emptyAgentDraft()
    draft.behaviour.greeting = 'Hey there.'
    const { store } = mountInstructions(agent({ description: 'My own words.', draft }))
    await flushPromises()

    expect(store.current?.description).toBe('My own words.')
    expect(store.current?.draft?.behaviour.greeting).toBe('Hey there.')
    expect(store.dirty).toBe(false)
  })

  it('derives nothing when the instructions are empty', async () => {
    getPromptMock.mockResolvedValue({ prompt: '' })
    const { store } = mountInstructions(agent())
    await flushPromises()

    expect(store.current?.description).toBeNull()
    expect(store.current?.draft?.behaviour.greeting).toBe('')
    expect(store.dirty).toBe(false)
  })
})
