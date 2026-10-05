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

  it('asks a guest to sign in instead of opening the list', async () => {
    wrapper = mountPicker({ guest: true })

    await wrapper.get('[data-testid="btn-model-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('gate')).toHaveLength(1)
    expect(wrapper.find('[data-testid="dropdown-model-panel"]').exists()).toBe(false)
  })
})
