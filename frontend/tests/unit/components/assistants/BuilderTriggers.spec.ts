import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import BuilderTriggers from '@/components/assistants/BuilderTriggers.vue'
import { emptyAgentDraft } from '@/services/api/agentsApi'
import { useAgentsStore } from '@/stores/agents'
import en from '@/i18n/en.json'

vi.mock('@/services/api/agentsApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/services/api/agentsApi')>()
  return {
    ...actual,
    agentsApi: {
      ...actual.agentsApi,
      triggers: vi.fn().mockResolvedValue({
        savedTasksEnabled: true,
        availableKinds: ['mail', 'widget', 'whatsapp'],
        rows: [],
      }),
    },
  }
})

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ user: { id: 4 } }),
}))

vi.mock('@/services/api/widgetsApi', () => ({
  listWidgets: vi.fn().mockResolvedValue([]),
}))

vi.mock('@/services/api/inboundEmailHandlersApi', () => ({
  inboundEmailHandlersApi: { list: vi.fn().mockResolvedValue([]) },
}))

function mountTriggers() {
  setActivePinia(createPinia())
  const store = useAgentsStore()
  store.current = {
    id: 3,
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
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  const wrapper = mount(BuilderTriggers, { global: { plugins: [i18n] } })
  return { wrapper, store }
}

describe('BuilderTriggers', () => {
  it('shows the empty state without the word cron', () => {
    const { wrapper } = mountTriggers()
    expect(wrapper.get('[data-testid="state-triggers-empty"]').text()).toContain(
      'only answers when someone starts a chat'
    )
    expect(wrapper.text().toLowerCase()).not.toContain('cron')
  })

  it('adds a weekly schedule from the form', async () => {
    const { wrapper, store } = mountTriggers()
    await wrapper.get('[data-testid="btn-add-schedule-empty"]').trigger('click')
    await wrapper.get('[data-testid="input-schedule-instruction"]').setValue('Summarise contracts')
    await wrapper.get('[data-testid="btn-save-schedule"]').trigger('click')
    expect(store.current?.draft?.triggers.schedules).toHaveLength(1)
    expect(store.current?.draft?.triggers.schedules[0]).toMatchObject({
      instruction: 'Summarise contracts',
    })
    expect(wrapper.text().toLowerCase()).not.toContain('cron')
  })
})
