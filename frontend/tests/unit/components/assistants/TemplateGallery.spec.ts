import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import TemplateGallery from '@/components/assistants/TemplateGallery.vue'
import { agentTemplatesApi, type AgentTemplate } from '@/services/api/agentTemplatesApi'
import enAssistants from '@/i18n/locales/en/assistants.json'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: { en: enAssistants },
})

function template(overrides: Partial<AgentTemplate> = {}): AgentTemplate {
  return {
    id: 'offer-writer',
    titleKey: 'templates.offerWriter.title',
    summaryKey: 'templates.offerWriter.summary',
    category: 'sales',
    pluginName: null,
    title: null,
    summary: null,
    usedBy: 0,
    installedAgentId: null,
    creates: { assistants: 1, prompts: 0, tasks: 0 },
    requires: [],
    ...overrides,
  } as AgentTemplate
}

function mountGallery(templates: AgentTemplate[]) {
  vi.spyOn(agentTemplatesApi, 'list').mockResolvedValue({
    peopleCount: null,
    publishReviewRequired: false,
    templates,
  })
  return mount(TemplateGallery, {
    global: { plugins: [i18n], stubs: { TemplateDetail: true, Icon: true } },
  })
}

describe('TemplateGallery', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('frames the examples by how they work and titles them by outcome', async () => {
    const wrapper = mountGallery([
      template({ id: 'offer-writer' }),
      template({
        id: 'daily-briefing',
        titleKey: 'templates.dailyBriefing.title',
        summaryKey: 'templates.dailyBriefing.summary',
        category: 'work',
        creates: { assistants: 1, prompts: 0, tasks: 1 },
      }),
    ])
    await flushPromises()

    expect(wrapper.get('[data-testid="heading-template-group-schedule"]').text()).toBe(
      'Runs by itself'
    )
    expect(wrapper.get('[data-testid="heading-template-group-chat"]').text()).toBe(
      'Answers in the chat'
    )
    expect(wrapper.get('[data-testid="card-template-daily-briefing"]').text()).toContain(
      'Your morning briefing, every weekday'
    )
    expect(wrapper.get('[data-testid="card-template-offer-writer"]').text()).toContain(
      'Write an offer from your price list'
    )
    // The scheduled example leads; the empty start stays with the chat group.
    const cards = wrapper.findAll('[data-testid^="card-template-"]')
    expect(cards[0].attributes('data-testid')).toBe('card-template-daily-briefing')
    expect(wrapper.find('[data-testid="card-template-empty"]').exists()).toBe(true)
  })

  it('shows one flat list without group headings when searching', async () => {
    const wrapper = mountGallery([
      template({ id: 'offer-writer' }),
      template({
        id: 'daily-briefing',
        titleKey: 'templates.dailyBriefing.title',
        summaryKey: 'templates.dailyBriefing.summary',
        category: 'work',
        creates: { assistants: 1, prompts: 0, tasks: 1 },
      }),
    ])
    await flushPromises()

    await wrapper.get('[data-testid="input-template-search"]').setValue('offer')
    expect(wrapper.find('[data-testid="heading-template-group-schedule"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="card-template-offer-writer"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="card-template-daily-briefing"]').exists()).toBe(false)
  })
})
