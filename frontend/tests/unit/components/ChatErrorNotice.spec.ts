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
        title: 'The answer did not finish',
        retry: 'Try again with another model',
        retryWith: 'Try again with {model}',
        retrySame: 'Try again',
        retrySameHint: 'No other model is available.',
        noRetry: 'Trying another model will not help.',
        chooseModel: 'Choose another model',
        showDetails: 'Show technical details',
        partialDraft: 'The text above is an unfinished draft.',
        reason: {
          unknown: 'Something went wrong while answering.',
          timeout: 'The model took too long to answer.',
          auth_failed: 'The AI service is not set up correctly.',
        },
      },
    },
  },
})

const mountNotice = (props: {
  errorReason?: string | null
  errorMessage?: string | null
  hasPartialAnswer?: boolean
  canRetryModel?: boolean
  errorDebug?: string | null
  recommendedModelId?: number | null
  failedModelId?: number | null
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

  it('shows the classified reason instead of a title-only banner', () => {
    const wrapper = mountNotice({ errorReason: 'timeout', canRetryModel: true })

    expect(wrapper.get('[data-testid="chat-error-title"]').text()).toBe('The answer did not finish')
    expect(wrapper.get('[data-testid="chat-error-body"]').text()).toContain('took too long')
  })

  it('prefers the backend localized sentence when it is already safe', () => {
    const wrapper = mountNotice({
      errorReason: 'timeout',
      errorMessage: 'The selected model took too long to answer.',
    })

    expect(wrapper.get('[data-testid="chat-error-body"]').text()).toBe(
      'The selected model took too long to answer.'
    )
  })

  it('tells the user a visible draft may be incomplete', () => {
    const wrapper = mountNotice({
      errorReason: 'unknown',
      hasPartialAnswer: true,
    })

    expect(wrapper.get('[data-testid="chat-error-partial"]').text()).toContain('unfinished draft')
  })

  it('emits retry with the recommended other model', async () => {
    const wrapper = mountNotice({
      canRetryModel: true,
      recommendedModelId: 42,
      failedModelId: 76,
      modelOptions: [
        { id: 42, label: 'GPT-4o' },
        { id: 7, label: 'Llama 4' },
        { id: 76, label: 'Claude Fable 5' },
      ],
    })

    expect(wrapper.get('[data-testid="btn-chat-error-retry"]').text()).toContain('GPT-4o')
    await wrapper.get('[data-testid="btn-chat-error-retry"]').trigger('click')
    expect(wrapper.emitted('retry')?.[0]).toEqual([42])
  })

  it('lets the user pick another model before retry', async () => {
    const wrapper = mountNotice({
      canRetryModel: true,
      recommendedModelId: 42,
      failedModelId: 76,
      modelOptions: [
        { id: 42, label: 'GPT-4o' },
        { id: 7, label: 'Llama 4' },
        { id: 76, label: 'Claude Fable 5' },
      ],
    })

    const select = wrapper.get('[data-testid="chat-error-model-select"]')
    await select.setValue('7')
    await wrapper.get('[data-testid="btn-chat-error-retry"]').trigger('click')
    expect(wrapper.emitted('retry')?.[0]).toEqual([7])
  })

  it('never lists the model that just failed', async () => {
    const wrapper = mountNotice({
      canRetryModel: true,
      recommendedModelId: 76,
      failedModelId: 76,
      modelOptions: [
        { id: 76, label: 'Claude Fable 5' },
        { id: 73, label: 'gpt-4o-mini' },
        { id: 12, label: 'claude-sonnet' },
      ],
    })

    const labels = wrapper
      .get('[data-testid="chat-error-model-select"]')
      .findAll('option')
      .map((option) => option.text())
    expect(labels).toEqual(['gpt-4o-mini', 'claude-sonnet'])
    expect(wrapper.get('[data-testid="btn-chat-error-retry"]').text()).toContain('gpt-4o-mini')
    await wrapper.get('[data-testid="btn-chat-error-retry"]').trigger('click')
    expect(wrapper.emitted('retry')?.[0]).toEqual([73])
  })

  it('hides the model picker when only one alternative is left', () => {
    const wrapper = mountNotice({
      canRetryModel: true,
      recommendedModelId: 76,
      failedModelId: 76,
      modelOptions: [
        { id: 76, label: 'Claude Fable 5' },
        { id: 73, label: 'gpt-4o-mini' },
      ],
    })

    expect(wrapper.find('[data-testid="chat-error-model-select"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="btn-chat-error-retry"]').text()).toContain('gpt-4o-mini')
  })

  it('offers a same-model retry only when no other model exists', () => {
    const wrapper = mountNotice({
      canRetryModel: true,
      recommendedModelId: 76,
      failedModelId: 76,
      modelOptions: [{ id: 76, label: 'Claude Fable 5' }],
    })

    expect(wrapper.get('[data-testid="chat-error-same-model"]').text()).toContain(
      'No other model is available'
    )
    expect(wrapper.get('[data-testid="btn-chat-error-retry"]').text()).toBe('Try again')
  })

  it('hides retry when another model would not help', () => {
    const wrapper = mountNotice({
      errorReason: 'auth_failed',
      canRetryModel: true,
      errorDebug: 'API key missing',
    })

    expect(wrapper.find('[data-testid="btn-chat-error-retry"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="chat-error-no-retry"]').text()).toContain('will not help')
    expect(wrapper.find('[data-testid="btn-chat-error-details"]').exists()).toBe(false)
  })

  it('shows admin diagnostics only for admins', async () => {
    isAdmin.value = true
    const wrapper = mountNotice({
      canRetryModel: true,
      errorDebug: 'Groq chat error: json_validate_failed',
    })

    expect(wrapper.find('[data-testid="btn-chat-error-details"]').exists()).toBe(true)
    await wrapper.get('[data-testid="btn-chat-error-details"]').trigger('click')
    expect(wrapper.get('[data-testid="chat-error-debug"]').text()).toContain('json_validate_failed')
  })
})
