import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import TemplateChecklist from '@/components/assistants/TemplateChecklist.vue'
import { useAgentsStore } from '@/stores/agents'
import { useChatsStore } from '@/stores/chats'
import { agentTemplatesApi } from '@/services/api/agentTemplatesApi'
import { agentsApi, emptyAgentDraft } from '@/services/api/agentsApi'
import enAssistants from '@/i18n/locales/en/assistants.json'

const pushMock = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: pushMock }),
}))

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: { en: enAssistants },
})

describe('TemplateChecklist', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    pushMock.mockReset()
    vi.restoreAllMocks()
  })

  it('does not claim checklistReady before template metadata loads', () => {
    vi.spyOn(agentTemplatesApi, 'list').mockReturnValue(new Promise(() => {}))
    const store = useAgentsStore()
    store.current = {
      id: 10,
      slug: 'offer-writer',
      name: 'Offer writer',
      description: null,
      icon: '',
      status: 'published',
      promptId: 1,
      parentId: null,
      source: 'template:offer-writer@2026.11.1',
      routable: false,
      publishedVersionId: 1,
      draft: emptyAgentDraft(),
      createdAt: 1,
      updatedAt: 1,
    }

    const wrapper = mount(TemplateChecklist, {
      global: { plugins: [i18n] },
    })

    expect(wrapper.find('[data-testid="text-template-ready"]').exists()).toBe(false)
  })

  it('clears knowledgeFolder requirement when ownFolder has uploaded files', async () => {
    vi.spyOn(agentTemplatesApi, 'list').mockResolvedValue({
      peopleCount: null,
      publishReviewRequired: false,
      templates: [
        {
          id: 'offer-writer',
          version: '2026.11.1',
          titleKey: 'templates.offerWriter.title',
          summaryKey: 'templates.offerWriter.summary',
          category: 'sales',
          tryIt: ['templates.offerWriter.try1'],
          requires: [
            {
              kind: 'knowledgeFolder',
              value: null,
              hintKey: 'templates.offerWriter.needsPriceList',
              optional: false,
              state: 'needsYou',
              action: 'chooseFolder',
            },
          ],
          usedBy: 1,
          installedAgentId: 10,
          installedVersion: '2026.11.1',
          updateAvailable: false,
        },
      ],
    })

    const store = useAgentsStore()
    store.current = {
      id: 10,
      slug: 'offer-writer',
      name: 'Offer writer',
      description: null,
      icon: '',
      status: 'published',
      promptId: 1,
      parentId: null,
      source: 'template:offer-writer@2026.11.1',
      routable: false,
      publishedVersionId: 1,
      draft: emptyAgentDraft(),
      createdAt: 1,
      updatedAt: 1,
    }

    const wrapper = mount(TemplateChecklist, {
      global: { plugins: [i18n] },
    })
    await flushPromises()

    expect(wrapper.find('[data-testid="btn-template-choose-folder"]').exists()).toBe(true)

    store.ownFileCount = 1
    await flushPromises()

    expect(wrapper.find('[data-testid="btn-template-choose-folder"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="text-template-ready"]').exists()).toBe(true)
  })

  it('hides update actions when viewing someone else shared assistant', async () => {
    vi.spyOn(agentTemplatesApi, 'list').mockResolvedValue({
      peopleCount: null,
      publishReviewRequired: false,
      templates: [
        {
          id: 'offer-writer',
          version: '2026.11.2',
          titleKey: 'templates.offerWriter.title',
          summaryKey: 'templates.offerWriter.summary',
          category: 'sales',
          tryIt: ['templates.offerWriter.try1'],
          requires: [],
          usedBy: 2,
          installedAgentId: 99,
          installedVersion: '2026.11.1',
          updateAvailable: true,
        },
      ],
    })

    const store = useAgentsStore()
    store.current = {
      id: 10,
      slug: 'offer-writer',
      name: 'Shared offer writer',
      description: null,
      icon: '',
      status: 'published',
      promptId: 1,
      parentId: null,
      source: 'template:offer-writer@2026.11.1',
      routable: false,
      publishedVersionId: 1,
      draft: emptyAgentDraft(),
      createdAt: 1,
      updatedAt: 1,
    }

    const wrapper = mount(TemplateChecklist, {
      global: { plugins: [i18n] },
    })
    await flushPromises()

    expect(wrapper.find('[data-testid="btn-template-update"]').exists()).toBe(false)
  })

  it('flushes draft, publishes, and opens an empty chat on Try it', async () => {
    vi.spyOn(agentTemplatesApi, 'list').mockResolvedValue({
      peopleCount: null,
      publishReviewRequired: false,
      templates: [],
    })
    const publishSpy = vi.spyOn(agentsApi, 'publish').mockResolvedValue({
      id: 2,
      version: 2,
      changelog: null,
      publishedByName: 'Demo',
      createdAt: 1,
    })

    const store = useAgentsStore()
    const draft = emptyAgentDraft()
    draft.behaviour.starterPrompts = ['Write an offer for Müller GmbH for 10 licenses.']
    store.current = {
      id: 10,
      slug: 'offer-writer',
      name: 'Offer writer',
      description: null,
      icon: '',
      status: 'published',
      promptId: 1,
      parentId: null,
      source: 'template:offer-writer@2026.11.1',
      routable: false,
      publishedVersionId: 1,
      draft,
      createdAt: 1,
      updatedAt: 1,
    }

    const chatsStore = useChatsStore()
    vi.spyOn(chatsStore, 'loadChats').mockResolvedValue()
    const emptyChatSpy = vi
      .spyOn(chatsStore, 'findOrCreateEmptyChat')
      .mockResolvedValue({ id: 55, title: 'New Chat' } as never)

    const wrapper = mount(TemplateChecklist, {
      global: { plugins: [i18n] },
    })
    await flushPromises()

    await wrapper.get('[data-testid="btn-template-try-0"]').trigger('click')
    await flushPromises()

    expect(publishSpy).toHaveBeenCalledWith(10, '')
    expect(emptyChatSpy).toHaveBeenCalled()
    expect(pushMock).toHaveBeenCalledWith({
      name: 'chat',
      query: {
        agentId: '10',
        prefill: 'Write an offer for Müller GmbH for 10 licenses.',
      },
    })
  })
})
