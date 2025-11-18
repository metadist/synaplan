import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import CommandPalette from '@/components/CommandPalette.vue'
import { useCommandsStore } from '@/stores/commands'
import { createPinia, setActivePinia } from 'pinia'

beforeEach(() => {
  setActivePinia(createPinia())
})

describe('CommandPalette', () => {
  it('should render when visible', () => {
    const wrapper = mount(CommandPalette, {
      props: {
        visible: true,
        query: '/',
      },
    })

    expect(wrapper.find('[role="menu"]').exists()).toBe(true)
  })

  it('should not render when not visible', () => {
    const wrapper = mount(CommandPalette, {
      props: {
        visible: false,
        query: '/',
      },
    })

    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
  })

  it('should show all commands when query is empty', () => {
    const commandsStore = useCommandsStore()
    const wrapper = mount(CommandPalette, {
      props: {
        visible: true,
        query: '/',
      },
      global: {
        plugins: [createPinia()],
      },
    })

    const items = wrapper.findAll('[role="menuitem"]')
    expect(items.length).toBe(commandsStore.commands.length)
  })

  it('should filter commands by query', () => {
    const commandsStore = useCommandsStore()
    const wrapper = mount(CommandPalette, {
      props: {
        visible: true,
        query: '/pic',
      },
      global: {
        plugins: [createPinia()],
      },
    })

    const items = wrapper.findAll('[role="menuitem"]')
    expect(items.length).toBeLessThan(commandsStore.commands.length)
    expect(items[0].text()).toContain('pic')
  })

  it('should emit select when command clicked', async () => {
    const wrapper = mount(CommandPalette, {
      props: {
        visible: true,
        query: '/',
      },
    })

    const firstItem = wrapper.find('[role="menuitem"]')
    await firstItem.trigger('click')

    expect(wrapper.emitted('select')).toBeTruthy()
    expect(wrapper.emitted('select')?.[0]).toBeTruthy()
  })

  it('should highlight first item by default', () => {
    const wrapper = mount(CommandPalette, {
      props: {
        visible: true,
        query: '/',
      },
    })

    const firstItem = wrapper.find('[role="menuitem"]')
    expect(firstItem.classes()).toContain('dropdown-item--active')
  })

  it('should handle arrow key navigation', async () => {
    const wrapper = mount(CommandPalette, {
      props: {
        visible: true,
        query: '/',
      },
    })

    const vm = wrapper.vm as any
    const event = new KeyboardEvent('keydown', { key: 'ArrowDown' })

    vm.handleKeyDown(event)
    await wrapper.vm.$nextTick()

    const items = wrapper.findAll('[role="menuitem"]')
    expect(items[1].classes()).toContain('dropdown-item--active')
  })

  it('should emit close on Escape', async () => {
    const wrapper = mount(CommandPalette, {
      props: {
        visible: true,
        query: '/',
      },
    })

    const vm = wrapper.vm as any
    const event = new KeyboardEvent('keydown', { key: 'Escape' })

    vm.handleKeyDown(event)
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('close')).toBeTruthy()
  })
})
