import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ModelDropdown from '@/components/ModelDropdown.vue'
import { useAiConfigStore } from '@/stores/aiConfig'
import type { AIModel } from '@/types/ai-models'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
  }),
}))

const chatModel = (overrides: Partial<AIModel> = {}): AIModel => ({
  id: 55,
  service: 'OpenAI',
  name: 'GPT-5.4',
  tag: 'CHAT',
  providerId: 'gpt-5.4',
  quality: 9,
  rating: 1,
  priceIn: 1,
  priceOut: 1,
  description: null,
  isSystemModel: false,
  features: ['reasoning'],
  reasoningLevels: ['low', 'medium', 'high'],
  reasoningEffortDefault: 'medium',
  ...overrides,
})

const mountPicker = (props: Record<string, unknown> = {}) =>
  mount(ModelDropdown, {
    attachTo: document.body,
    props: {
      modelValue: null,
      levels: ['low', 'medium', 'high'],
      reasoningEffort: 'medium',
      ...props,
    },
    global: {
      mocks: { $t: (key: string) => key },
      stubs: { Icon: true },
    },
  })

describe('ModelDropdown', () => {
  let wrapper: ReturnType<typeof mountPicker> | undefined

  beforeEach(() => {
    setActivePinia(createPinia())
    const store = useAiConfigStore()
    store.models.CHAT = [chatModel(), chatModel({ id: 56, name: 'Claude', service: 'Anthropic' })]
    store.defaults.CHAT = 55
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = undefined
  })

  it('shows the effective model name and the reasoning level on the chip', () => {
    wrapper = mountPicker()

    expect(wrapper.get('[data-testid="model-chip-name"]').text()).toBe('GPT-5.4')
    expect(wrapper.get('[data-testid="reasoning-level-current"]').text()).toContain(
      'chatInput.reasoningLevel.medium'
    )
  })

  it('hides the reasoning level when the model has none', () => {
    wrapper = mountPicker({ levels: [], reasoningEffort: '' })

    expect(wrapper.find('[data-testid="reasoning-level-current"]').exists()).toBe(false)
  })

  it('opens on Arrow Down and focuses the current model', async () => {
    wrapper = mountPicker({ modelValue: 55 })
    const trigger = wrapper.get('[data-testid="btn-model-toggle"]')

    await trigger.trigger('keydown.down')
    await flushPromises()

    expect(wrapper.find('[data-testid="dropdown-model-panel"]').exists()).toBe(true)
    expect(document.activeElement).toBe(wrapper.get('[data-testid="btn-model-55"]').element)
  })

  it('moves between levels with the arrow keys and wraps', async () => {
    wrapper = mountPicker()
    await wrapper.get('[data-testid="btn-model-toggle"]').trigger('click')
    await wrapper.get('[data-testid="btn-reasoning-toggle"]').trigger('click')
    await flushPromises()

    const medium = wrapper.get('[data-testid="btn-reasoning-medium"]')
    ;(medium.element as HTMLButtonElement).focus()
    await medium.trigger('keydown.down')

    const high = wrapper.get('[data-testid="btn-reasoning-high"]')
    expect(document.activeElement).toBe(high.element)

    await high.trigger('keydown.down')
    expect(document.activeElement).toBe(wrapper.get('[data-testid="btn-reasoning-low"]').element)
  })

  it('returns focus to the reasoning row when Escape closes the level list', async () => {
    wrapper = mountPicker()
    await wrapper.get('[data-testid="btn-model-toggle"]').trigger('click')
    const reasoning = wrapper.get('[data-testid="btn-reasoning-toggle"]')
    await reasoning.trigger('click')
    await flushPromises()

    await wrapper.get('[data-testid="btn-reasoning-medium"]').trigger('keydown.escape')
    await flushPromises()

    expect(wrapper.find('[data-testid="dropdown-model-panel"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="dropdown-reasoning-panel"]').exists()).toBe(false)
    expect(document.activeElement).toBe(reasoning.element)
  })

  it('keeps the model list open after a level is chosen', async () => {
    wrapper = mountPicker()
    await wrapper.get('[data-testid="btn-model-toggle"]').trigger('click')
    const reasoning = wrapper.get('[data-testid="btn-reasoning-toggle"]')
    await reasoning.trigger('click')
    await flushPromises()

    await wrapper.get('[data-testid="btn-reasoning-high"]').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('update:reasoningEffort')?.[0]).toEqual(['high'])
    expect(wrapper.find('[data-testid="dropdown-model-panel"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="dropdown-reasoning-panel"]').exists()).toBe(false)
    expect(document.activeElement).toBe(reasoning.element)
  })

  it('emits the picked model and closes', async () => {
    wrapper = mountPicker()
    await wrapper.get('[data-testid="btn-model-toggle"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-testid="btn-model-56"]').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([56])
    expect(wrapper.find('[data-testid="dropdown-model-panel"]').exists()).toBe(false)
  })

  it('focuses the model whose name matches the typed letters without selecting it', async () => {
    wrapper = mountPicker()
    await wrapper.get('[data-testid="btn-model-toggle"]').trigger('click')
    await flushPromises()

    const list = wrapper.get('[role="listbox"]')
    await list.trigger('keydown', { key: 'c' })
    await list.trigger('keydown', { key: 'l' })
    await flushPromises()

    expect(document.activeElement).toBe(wrapper.get('[data-testid="btn-model-56"]').element)
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    expect(wrapper.find('[data-testid="dropdown-model-panel"]').exists()).toBe(true)
  })

  it('leaves Space to the focused row while nothing has been typed', async () => {
    wrapper = mountPicker()
    await wrapper.get('[data-testid="btn-model-toggle"]').trigger('click')
    await flushPromises()

    const list = wrapper.get('[role="listbox"]')
    const idle = new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true })
    list.element.dispatchEvent(idle)
    expect(idle.defaultPrevented).toBe(false)

    await list.trigger('keydown', { key: 'c' })
    const typed = new KeyboardEvent('keydown', { key: ' ', bubbles: true, cancelable: true })
    list.element.dispatchEvent(typed)
    expect(typed.defaultPrevented).toBe(true)
  })

  describe('filter', () => {
    const manyModels = () => [
      chatModel(),
      chatModel({ id: 56, name: 'Claude Sonnet 5', service: 'Anthropic', providerId: 'sonnet' }),
      chatModel({ id: 57, name: 'Claude Haiku 4.5', service: 'Anthropic', providerId: 'haiku' }),
      chatModel({ id: 58, name: 'Gemini 3.1 Pro', service: 'Google', providerId: 'gemini' }),
      chatModel({ id: 59, name: 'Llama 4', service: 'Groq', providerId: 'llama' }),
      chatModel({ id: 60, name: 'Mistral Large', service: 'Mistral', providerId: 'mistral' }),
    ]

    beforeEach(() => {
      useAiConfigStore().models.CHAT = manyModels()
    })

    const openWithFilter = async () => {
      wrapper = mountPicker()
      await wrapper.get('[data-testid="btn-model-toggle"]').trigger('keydown.down')
      await flushPromises()
      return wrapper.get('[data-testid="input-model-filter"]')
    }

    const visibleIds = () =>
      wrapper!
        .findAll('[role="option"]')
        .map((option) => option.attributes('data-testid'))
        .filter((id): id is string => id !== undefined)

    it('hides the filter for a short list', async () => {
      useAiConfigStore().models.CHAT = [chatModel()]
      wrapper = mountPicker()
      await wrapper.get('[data-testid="btn-model-toggle"]').trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid="input-model-filter"]').exists()).toBe(false)
    })

    it('focuses the filter when opened from the keyboard and narrows by name or provider', async () => {
      const input = await openWithFilter()
      expect(document.activeElement).toBe(input.element)

      await input.setValue('anthropic')
      expect(visibleIds()).toEqual(['btn-model-57', 'btn-model-56'])

      await input.setValue('GEMINI pro')
      expect(visibleIds()).toEqual(['btn-model-58'])
    })

    it('picks the first match on Enter', async () => {
      const input = await openWithFilter()
      await input.setValue('haiku')
      await input.trigger('keydown.enter')
      await flushPromises()

      expect(wrapper!.emitted('update:modelValue')?.[0]).toEqual([57])
      expect(wrapper!.find('[data-testid="dropdown-model-panel"]').exists()).toBe(false)
    })

    it('says so when nothing matches, and Escape clears before it closes', async () => {
      const input = await openWithFilter()
      await input.setValue('zzz')

      expect(wrapper!.get('[data-testid="text-model-filter-empty"]').text()).toContain(
        'chatInput.modelDropdown.noMatch'
      )

      await input.trigger('keydown.escape')
      expect((input.element as HTMLInputElement).value).toBe('')
      expect(wrapper!.find('[data-testid="dropdown-model-panel"]').exists()).toBe(true)

      await input.trigger('keydown.escape')
      await flushPromises()
      expect(wrapper!.find('[data-testid="dropdown-model-panel"]').exists()).toBe(false)
    })

    it('sends letters typed in the list to the filter', async () => {
      const input = await openWithFilter()
      await input.trigger('keydown.down')
      const list = wrapper!.get('[role="listbox"]')
      await list.trigger('keydown', { key: 'l' })

      expect((input.element as HTMLInputElement).value).toBe('l')
      expect(document.activeElement).toBe(input.element)
    })
  })

  it('asks a guest to sign in instead of opening the list', async () => {
    wrapper = mountPicker({ guest: true })

    await wrapper.get('[data-testid="btn-model-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('gate')).toHaveLength(1)
    expect(wrapper.find('[data-testid="dropdown-model-panel"]').exists()).toBe(false)
  })
})
