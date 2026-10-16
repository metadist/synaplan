import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import { mount, type VueWrapper } from '@vue/test-utils'
import { usePaletteFocus } from '@/composables/search/usePaletteFocus'

const isOpen = ref(false)

const Host = defineComponent({
  setup() {
    const input = ref<HTMLInputElement | null>(null)
    usePaletteFocus(isOpen, input, () => undefined)
    return () =>
      h('div', [
        h('input', { 'data-testid': 'composer' }),
        h('div', { role: 'dialog' }, [
          h('input', { ref: input, 'data-testid': 'palette' }),
          h('button', { 'data-testid': 'row-control' }),
        ]),
        h('div', { 'data-testid': 'comp-notification-container' }, [
          h('button', { 'data-testid': 'undo' }),
        ]),
      ])
  },
})

let wrapper: VueWrapper | null = null

const mountOpen = async () => {
  wrapper = mount(Host, { attachTo: document.body })
  wrapper.get<HTMLInputElement>('[data-testid="composer"]').element.focus()
  isOpen.value = true
  await nextTick()
  await nextTick()
  return wrapper
}

const active = () => (document.activeElement as HTMLElement | null)?.dataset.testid

describe('usePaletteFocus', () => {
  afterEach(() => {
    isOpen.value = false
    wrapper?.unmount()
    wrapper = null
  })

  it('focuses the field on open and returns focus on close', async () => {
    await mountOpen()
    expect(active()).toBe('palette')

    isOpen.value = false
    await nextTick()
    await nextTick()
    expect(active()).toBe('composer')
  })

  it('pulls focus back when a view behind the palette grabs it', async () => {
    const host = await mountOpen()
    host.get<HTMLInputElement>('[data-testid="composer"]').element.focus()
    expect(active()).toBe('palette')
  })

  it('leaves focus in dialogs and on the Undo toast', async () => {
    const host = await mountOpen()
    host.get<HTMLButtonElement>('[data-testid="row-control"]').element.focus()
    expect(active()).toBe('row-control')
    host.get<HTMLButtonElement>('[data-testid="undo"]').element.focus()
    expect(active()).toBe('undo')
  })
})
