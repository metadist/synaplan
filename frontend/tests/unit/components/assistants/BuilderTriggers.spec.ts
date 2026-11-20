import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import BuilderTriggers from '@/components/assistants/BuilderTriggers.vue'
import { resetAccountTimezone } from '@/composables/useAccountTimezone'
import { emptyAgentDraft } from '@/services/api/agentsApi'
import { useAgentsStore } from '@/stores/agents'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'

const en = loadAllMessages('en')

const { mockGetProfile } = vi.hoisted(() => ({
  mockGetProfile: vi.fn(),
}))

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
  useAuthStore: () => ({
    user: { id: 4 },
    isImpersonating: false,
  }),
}))

vi.mock('@/services/api/profileApi', () => ({
  profileApi: {
    getProfile: (...args: unknown[]) => mockGetProfile(...args),
    updateProfile: vi.fn(),
  },
}))

vi.mock('@/services/api/widgetsApi', () => ({
  listWidgets: vi.fn().mockResolvedValue([]),
}))

vi.mock('@/services/api/inboundEmailHandlersApi', () => ({
  inboundEmailHandlersApi: { list: vi.fn().mockResolvedValue([]) },
}))

function mountTriggers(
  triggers: ReturnType<typeof emptyAgentDraft>['triggers'] = { events: [], schedules: [] }
) {
  setActivePinia(createPinia())
  const store = useAgentsStore()
  const draft = emptyAgentDraft()
  draft.triggers = triggers
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
    draft,
    createdAt: 1,
    updatedAt: 1,
  }
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: asI18nSchema(en) } })
  const wrapper = mount(BuilderTriggers, { global: { plugins: [i18n] } })
  return { wrapper, store }
}

describe('BuilderTriggers', () => {
  beforeEach(() => {
    resetAccountTimezone()
    mockGetProfile.mockReset()
    mockGetProfile.mockResolvedValue({
      success: true,
      profile: { timezone: 'Europe/Berlin' },
    })
  })

  it('shows the empty state without the word cron', () => {
    const { wrapper } = mountTriggers()
    expect(wrapper.get('[data-testid="state-triggers-empty"]').text()).toContain(
      'only answers when someone starts a chat'
    )
    expect(wrapper.text().toLowerCase()).not.toContain('cron')
  })

  it('says once that the agent runs as you and keeps identity words off personal rows', () => {
    const { wrapper } = mountTriggers({
      events: [{ id: 'wdg-1', kind: 'widget', widget: '4:main', enabled: true }],
      schedules: [{ id: 'sch-1', name: 'Morning news', enabled: true }],
    })

    expect(wrapper.get('[data-testid="text-runs-as-line"]').text()).toBe('Runs as you.')
    const text = wrapper.text()
    expect(text).not.toContain('Runs as the person talking')
    expect(text).not.toContain('Runs as the person using the app')
    expect(text).not.toContain('Runs on its own (as you)')
  })

  it('names the shared mailbox on a department mail event', () => {
    const { wrapper } = mountTriggers({
      events: [
        {
          id: 'mail-1',
          kind: 'mail',
          mailbox: '4:inbox',
          department: 'support@example.org',
          enabled: true,
        },
      ],
      schedules: [],
    })

    expect(wrapper.get('[data-testid="row-event-mail-1"]').text()).toContain(
      'Sends mail from the shared mailbox.'
    )
  })

  it('adds a weekly schedule from the form', async () => {
    const { wrapper, store } = mountTriggers()
    await wrapper.get('[data-testid="btn-add-schedule-empty"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="input-schedule-instruction"]').setValue('Summarise contracts')
    await wrapper.get('[data-testid="btn-save-schedule"]').trigger('click')
    expect(store.current?.draft?.triggers.schedules).toHaveLength(1)
    expect(store.current?.draft?.triggers.schedules[0]).toMatchObject({
      instruction: 'Summarise contracts',
      tz: 'Europe/Berlin',
    })
    expect(wrapper.text().toLowerCase()).not.toContain('cron')
  })
})
