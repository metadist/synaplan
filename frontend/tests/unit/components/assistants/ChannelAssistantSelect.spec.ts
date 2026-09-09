import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ChannelAssistantSelect from '@/components/assistants/ChannelAssistantSelect.vue'
import en from '@/i18n/en.json'

const gallery = vi.fn()
const agentsEnabled = vi.fn(() => true)

vi.mock('@/composables/useAgentsFeature', () => ({
  isAgentsEnabled: () => agentsEnabled(),
}))

vi.mock('@/services/api/agentsApi', () => ({
  agentsApi: {
    gallery: (...args: unknown[]) => gallery(...args),
  },
}))

function mountSelect() {
  const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })
  return mount(ChannelAssistantSelect, {
    props: {
      modelValue: null,
      label: 'Use an assistant',
      noneLabel: 'None',
      hint: 'Optional',
    },
    global: { plugins: [i18n] },
  })
}

describe('ChannelAssistantSelect', () => {
  it('hides when assistants are off', async () => {
    agentsEnabled.mockReturnValue(false)
    const wrapper = mountSelect()
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="select-channel-assistant"]').exists()).toBe(false)
  })

  it('lists published assistants and emits the id', async () => {
    agentsEnabled.mockReturnValue(true)
    gallery.mockResolvedValue([
      { id: 12, name: 'Contract review', status: 'published', origin: 'mine' },
      { id: 13, name: 'Draft only', status: 'draft', origin: 'mine' },
    ])
    const wrapper = mountSelect()
    await wrapper.vm.$nextTick()
    await Promise.resolve()
    await wrapper.vm.$nextTick()

    const options = wrapper.findAll('option')
    expect(options.map((option) => option.text())).toEqual(['None', 'Contract review'])

    await wrapper.get('select').setValue('12')
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([12])
  })
})
