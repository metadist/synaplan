import { describe, it, expect, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ReasoningLevelMenu from '@/components/ReasoningLevelMenu.vue'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
  }),
}))

const mountMenu = () =>
  mount(ReasoningLevelMenu, {
    attachTo: document.body,
    props: {
      levels: ['low', 'medium', 'high'],
      modelValue: 'medium',
    },
    global: {
      mocks: { $t: (key: string) => key },
    },
  })

describe('ReasoningLevelMenu keyboard', () => {
  let wrapper: ReturnType<typeof mountMenu> | undefined

  afterEach(() => {
    wrapper?.unmount()
    wrapper = undefined
  })

  it('opens on Arrow Down and focuses the current level', async () => {
    wrapper = mountMenu()
    const trigger = wrapper.get('[data-testid="btn-reasoning-toggle"]')

    await trigger.trigger('keydown.down')
    await flushPromises()

    expect(wrapper.find('[data-testid="dropdown-reasoning-panel"]').exists()).toBe(true)
    expect(document.activeElement).toBe(wrapper.get('[data-testid="btn-reasoning-medium"]').element)
  })

  it('moves between levels with the arrow keys and wraps', async () => {
    wrapper = mountMenu()
    await wrapper.get('[data-testid="btn-reasoning-toggle"]').trigger('keydown.down')
    await flushPromises()

    const high = wrapper.get('[data-testid="btn-reasoning-high"]')
    await wrapper.get('[data-testid="btn-reasoning-medium"]').trigger('keydown.down')
    expect(document.activeElement).toBe(high.element)

    await high.trigger('keydown.down')
    expect(document.activeElement).toBe(wrapper.get('[data-testid="btn-reasoning-low"]').element)
  })

  it('returns focus to the trigger when Escape closes the list', async () => {
    wrapper = mountMenu()
    const trigger = wrapper.get('[data-testid="btn-reasoning-toggle"]')
    await trigger.trigger('keydown.down')
    await flushPromises()

    await wrapper.get('[data-testid="btn-reasoning-medium"]').trigger('keydown.escape')
    await flushPromises()

    expect(wrapper.find('[data-testid="dropdown-reasoning-panel"]').exists()).toBe(false)
    expect(document.activeElement).toBe(trigger.element)
  })

  it('returns focus to the trigger after a level is chosen', async () => {
    wrapper = mountMenu()
    const trigger = wrapper.get('[data-testid="btn-reasoning-toggle"]')
    await trigger.trigger('click')
    await flushPromises()

    await wrapper.get('[data-testid="btn-reasoning-high"]').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['high'])
    expect(wrapper.find('[data-testid="dropdown-reasoning-panel"]').exists()).toBe(false)
    expect(document.activeElement).toBe(trigger.element)
  })
})
