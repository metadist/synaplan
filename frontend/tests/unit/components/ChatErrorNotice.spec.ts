import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { ref } from 'vue'

import ChatErrorNotice from '@/components/ChatErrorNotice.vue'

const isAdmin = ref(false)

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isAdmin() {
      return isAdmin.value
    },
  }),
}))

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      error: { adminOnly: 'Admin only' },
      chatError: {
        retry: 'Try again with another model',
        retryWith: 'Try again with {model}',
        chooseModel: 'Choose another model',
        showDetails: 'Show technical details',
        noRetryHint: 'Please try again later or contact support.',
        reason: {
          schema_mismatch: {
            title: 'The model could not complete this request',
            body: 'Please try another model.',
          },
          auth_failed: {
            title: 'The AI service is not set up',
            body: 'Contact support.',
          },
          unknown: {
            title: 'The model could not answer',
            body: 'Please try again.',
          },
        },
      },
    },
  },
})

const mountNotice = (props: {
  errorReason: string
  canRetryModel?: boolean
  errorDebug?: string | null
  recommendedModelLabel?: string | null
  recommendedModelId?: number | null
  modelOptions?: { id: number; label: string }[]
}) =>
  mount(ChatErrorNotice, {
    props,
    global: {
      plugins: [i18n],
      stubs: { Icon: true },
    },
  })

describe('ChatErrorNotice', () => {
  beforeEach(() => {
    isAdmin.value = false
  })

  it('shows the localized reason and emits retry with the recommended model', async () => {
    const wrapper = mountNotice({
      errorReason: 'schema_mismatch',
      canRetryModel: true,
      recommendedModelLabel: 'GPT-4o',
      recommendedModelId: 42,
    })

    expect(wrapper.get('[data-testid="chat-error-title"]').text()).toContain(
      'The model could not complete this request'
    )
    expect(wrapper.get('[data-testid="btn-chat-error-retry"]').text()).toContain('GPT-4o')
    expect(wrapper.find('[data-testid="chat-error-debug"]').exists()).toBe(false)

    await wrapper.get('[data-testid="btn-chat-error-retry"]').trigger('click')
    expect(wrapper.emitted('retry')?.[0]).toEqual([42])
  })

  it('lets the user pick another model before retry', async () => {
    const wrapper = mountNotice({
      errorReason: 'schema_mismatch',
      canRetryModel: true,
      recommendedModelLabel: 'GPT-4o',
      recommendedModelId: 42,
      modelOptions: [
        { id: 42, label: 'GPT-4o' },
        { id: 7, label: 'Llama 4' },
      ],
    })

    const select = wrapper.get('[data-testid="chat-error-model-select"]')
    await select.setValue('7')
    await wrapper.get('[data-testid="btn-chat-error-retry"]').trigger('click')
    expect(wrapper.emitted('retry')?.[0]).toEqual([7])
  })

  it('hides retry for auth failures', () => {
    const wrapper = mountNotice({
      errorReason: 'auth_failed',
      canRetryModel: false,
      errorDebug: 'API key missing',
    })

    expect(wrapper.find('[data-testid="btn-chat-error-retry"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="chat-error-no-retry"]').text()).toContain('later')
    expect(wrapper.find('[data-testid="btn-chat-error-details"]').exists()).toBe(false)
  })

  it('shows admin diagnostics only for admins', async () => {
    isAdmin.value = true
    const wrapper = mountNotice({
      errorReason: 'schema_mismatch',
      canRetryModel: true,
      errorDebug: 'Groq chat error: json_validate_failed',
    })

    expect(wrapper.find('[data-testid="btn-chat-error-details"]').exists()).toBe(true)
    await wrapper.get('[data-testid="btn-chat-error-details"]').trigger('click')
    expect(wrapper.get('[data-testid="chat-error-debug"]').text()).toContain('json_validate_failed')
  })
})
