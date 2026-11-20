import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'

import ChatRunLimitSetting from '@/components/settings/ChatRunLimitSetting.vue'
import type { ChatRunLimit } from '@/services/api/chatRunLimitApi'

const getChatRunLimit = vi.fn<() => Promise<ChatRunLimit>>()
const saveChatRunLimit = vi.fn<(percent: number | null) => Promise<ChatRunLimit>>()
const success = vi.fn()
const error = vi.fn()

vi.mock('@/services/api/chatRunLimitApi', () => ({
  getChatRunLimit: () => getChatRunLimit(),
  saveChatRunLimit: (percent: number | null) => saveChatRunLimit(percent),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success, error }),
}))

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      settings: {
        chatRunLimit: {
          title: 'Cost limit per long answer',
          description: 'At most this share.',
          share: 'Share of your monthly budget',
          option: '{percent} % – up to {amount} per answer',
          default: 'default',
          monthly: 'Your monthly budget: {amount}',
          noBudget: 'No monthly budget.',
          saved: 'Saved.',
          saveFailed: 'Not saved',
        },
      },
    },
  },
})

function limit(overrides: Partial<ChatRunLimit> = {}): ChatRunLimit {
  return {
    enabled: true,
    percent: 10,
    default: 10,
    choices: [5, 10, 20, 25, 50, 100],
    monthlyBudget: '20.00',
    perRun: '2.00',
    ...overrides,
  }
}

async function render() {
  const wrapper = mount(ChatRunLimitSetting, { global: { plugins: [i18n] } })
  await flushPromises()
  return wrapper
}

describe('ChatRunLimitSetting', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows each share with what it allows per answer', async () => {
    getChatRunLimit.mockResolvedValue(limit())

    const wrapper = await render()

    const options = wrapper.findAll('option').map((option) => option.text())
    expect(options).toHaveLength(6)
    expect(options[1]).toBe('10 % – up to $2.00 per answer · default')
    expect(options[3]).toBe('25 % – up to $5.00 per answer')
    expect(wrapper.find('[data-testid="chat-run-limit-monthly"]').text()).toContain('$20.00')
    expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('10')
  })

  it('saves the chosen share', async () => {
    getChatRunLimit.mockResolvedValue(limit())
    saveChatRunLimit.mockResolvedValue(limit({ percent: 25, perRun: '5.00' }))
    const wrapper = await render()

    await wrapper.find('select').setValue('25')
    await flushPromises()

    expect(saveChatRunLimit).toHaveBeenCalledWith(25)
    expect(success).toHaveBeenCalledWith('Saved.')
  })

  it('goes back to the saved share when saving fails', async () => {
    getChatRunLimit.mockResolvedValue(limit({ percent: 20 }))
    saveChatRunLimit.mockRejectedValue(new Error('percent must be one of the offered shares'))
    const wrapper = await render()

    await wrapper.find('select').setValue('50')
    await flushPromises()

    expect(error).toHaveBeenCalledWith('percent must be one of the offered shares')
    expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('20')
  })

  it('says there is no limit when the plan has no monthly budget', async () => {
    getChatRunLimit.mockResolvedValue(limit({ monthlyBudget: null, perRun: null }))

    const wrapper = await render()

    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.find('[data-testid="chat-run-limit-unlimited"]').text()).toBe(
      'No monthly budget.'
    )
  })

  it('stays hidden while long answers are off or the limit cannot be read', async () => {
    getChatRunLimit.mockResolvedValue(limit({ enabled: false }))
    expect((await render()).find('[data-testid="section-chat-run-limit"]').exists()).toBe(false)

    getChatRunLimit.mockRejectedValue(new Error('down'))
    expect((await render()).find('[data-testid="section-chat-run-limit"]').exists()).toBe(false)
  })
})
