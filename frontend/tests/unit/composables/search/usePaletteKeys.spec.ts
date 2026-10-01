import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { FolderIcon } from '@heroicons/vue/24/outline'
import { usePaletteKeys } from '@/composables/search/usePaletteKeys'
import type { SearchResult, SettingControl } from '@/composables/search/types'

const result = (id: string) =>
  ({ id, kind: 'page', title: id, icon: FolderIcon, run: () => {} }) as SearchResult

function setup(options: { open?: boolean; canOpen?: boolean } = {}) {
  const state = {
    query: ref(''),
    results: ref([result('a'), result('b'), result('c')]),
    activeIndex: ref(0),
    activeSetting: ref<SettingControl | null>(null),
    select: vi.fn(),
    switchSetting: vi.fn(),
    close: vi.fn(),
    toggle: vi.fn(),
    isOpen: () => options.open ?? true,
    canOpen: () => options.canOpen ?? true,
    focusSetting: vi.fn(() => true),
    actions: { isOpen: ref(false), open: vi.fn(() => true), handle: vi.fn(() => false) },
  }
  let onKeydown: (event: KeyboardEvent) => void = () => {}
  const wrapper = mount(
    defineComponent({
      setup() {
        onKeydown = usePaletteKeys(state).onKeydown
        return () => h('div')
      },
    })
  )
  const press = (key: string, init: KeyboardEventInit = {}) =>
    onKeydown(new KeyboardEvent('keydown', { key, cancelable: true, ...init }))
  return { state, press, wrapper, keydown: (event: KeyboardEvent) => onKeydown(event) }
}

describe('usePaletteKeys', () => {
  it('wraps arrow navigation and jumps with Home/End on an empty query', () => {
    const { state, press, wrapper } = setup()
    press('ArrowUp')
    expect(state.activeIndex.value).toBe(2)
    press('ArrowDown')
    expect(state.activeIndex.value).toBe(0)
    press('End')
    expect(state.activeIndex.value).toBe(2)
    press('Home')
    expect(state.activeIndex.value).toBe(0)

    state.query.value = 'x'
    press('End')
    expect(state.activeIndex.value).toBe(0)
    wrapper.unmount()
  })

  it('runs the active row, in a new tab with Ctrl/Cmd', () => {
    const { state, press, wrapper } = setup()
    state.activeIndex.value = 1
    press('Enter')
    expect(state.select).toHaveBeenLastCalledWith(state.results.value[1], false)
    press('Enter', { metaKey: true })
    expect(state.select).toHaveBeenLastCalledWith(state.results.value[1], true)
    wrapper.unmount()
  })

  it('switches the active setting on Shift+Enter instead of running the row', () => {
    const { state, press, wrapper } = setup()
    const control = { key: 'FEATURE_X' } as unknown as SettingControl
    state.activeSetting.value = control
    press('Enter', { shiftKey: true })
    expect(state.switchSetting).toHaveBeenCalledWith(control)
    expect(state.select).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('opens the action pane on Tab and lets the open pane take the keys first', () => {
    const { state, press, wrapper } = setup()
    press('Tab')
    expect(state.actions.open).toHaveBeenCalledTimes(1)
    press('Tab', { shiftKey: true })
    expect(state.actions.open).toHaveBeenCalledTimes(1)

    state.actions.handle.mockReturnValue(true)
    press('Escape')
    press('Enter')
    expect(state.close).not.toHaveBeenCalled()
    expect(state.select).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('moves on from the open pane to the setting control with Tab', () => {
    const { state, press, wrapper } = setup()
    state.actions.isOpen.value = true
    press('Tab')
    expect(state.focusSetting).toHaveBeenCalledTimes(1)
    expect(state.actions.open).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('focuses the setting control directly when there is no pane to open', () => {
    const { state, press, wrapper } = setup()
    state.actions.open.mockReturnValue(false)
    press('Tab')
    expect(state.focusSetting).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('keeps Enter from reaching a confirmation it just opened on document', () => {
    const { state, wrapper, keydown } = setup()
    const confirmOnDocument = vi.fn()
    document.addEventListener('keydown', confirmOnDocument)
    const input = document.createElement('input')
    document.body.appendChild(input)
    input.addEventListener('keydown', keydown)

    input.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Enter', shiftKey: true, bubbles: true })
    )
    state.actions.handle.mockReturnValue(true)
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))

    expect(confirmOnDocument).not.toHaveBeenCalled()
    document.removeEventListener('keydown', confirmOnDocument)
    input.remove()
    wrapper.unmount()
  })

  it('closes on Escape', () => {
    const { state, press, wrapper } = setup()
    press('Escape')
    expect(state.close).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('toggles on Ctrl/Cmd+K only where the palette may open, and stops after unmount', () => {
    const shortcut = () =>
      window.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, cancelable: true })
      )

    const blocked = setup({ open: false, canOpen: false })
    shortcut()
    expect(blocked.state.toggle).not.toHaveBeenCalled()
    blocked.wrapper.unmount()

    const allowed = setup({ open: false })
    shortcut()
    expect(allowed.state.toggle).toHaveBeenCalledTimes(1)
    allowed.wrapper.unmount()
    shortcut()
    expect(allowed.state.toggle).toHaveBeenCalledTimes(1)
  })
})
