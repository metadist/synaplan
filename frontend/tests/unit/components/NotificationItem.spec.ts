import { describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import NotificationItem from '@/components/NotificationItem.vue'
import type { Notification } from '@/composables/useNotification'

function mountToast(overrides: Partial<Notification> = {}) {
  const onClick = vi.fn()
  const notification: Notification = {
    id: 'n1',
    type: 'success',
    title: 'Test toast',
    message: 'Hello',
    action: { label: 'Aktion', onClick },
    ...overrides,
  }
  const wrapper = mount(NotificationItem, { props: { notification } })
  return { wrapper, onClick, el: wrapper.get('[data-testid="comp-notification-item"]').element }
}

function pointer(el: Element, type: string, x: number, y: number): void {
  el.dispatchEvent(
    new PointerEvent(type, {
      bubbles: true,
      cancelable: true,
      pointerId: 1,
      button: 0,
      clientX: x,
      clientY: y,
    })
  )
}

async function swipe(el: Element, dx: number, dy = 0): Promise<void> {
  pointer(el, 'pointerdown', 20, 20)
  pointer(el, 'pointermove', 20 + dx, 20 + dy)
  pointer(el, 'pointerup', 20 + dx, 20 + dy)
  await flushPromises()
}

describe('NotificationItem swipe to dismiss', () => {
  it('slides off and closes after a rightward swipe', async () => {
    const { wrapper, el } = mountToast()

    await swipe(el, 120)

    expect(el.getAttribute('style')).toContain('translateX(')
    el.dispatchEvent(
      new TransitionEvent('transitionend', { propertyName: 'transform', bubbles: true })
    )
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('snaps back when the rightward swipe is too short', async () => {
    const { wrapper, el } = mountToast()

    await swipe(el, 24)

    expect(wrapper.emitted('close')).toBeUndefined()
    expect(el.getAttribute('style') ?? '').toContain('translateX(0px)')
  })

  it('ignores a leftward swipe', async () => {
    const { wrapper, el } = mountToast()

    await swipe(el, -120)

    expect(wrapper.emitted('close')).toBeUndefined()
    expect(el.getAttribute('style')).toBeNull()
  })

  it('still runs the action after a press that does not move', async () => {
    const { wrapper, onClick, el } = mountToast()

    pointer(el, 'pointerdown', 20, 20)
    pointer(el, 'pointerup', 20, 20)
    await wrapper.get('[data-testid="btn-notification-action"]').trigger('click')

    expect(onClick).toHaveBeenCalledOnce()
    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('still closes from the close button', async () => {
    const { wrapper } = mountToast()

    await wrapper.get('[data-testid="btn-notification-close"]').trigger('click')

    expect(wrapper.emitted('close')).toHaveLength(1)
  })

  it('does not run the action when the swipe started on the toast', async () => {
    const { wrapper, onClick, el } = mountToast()

    await swipe(el, 40)
    await wrapper.get('[data-testid="btn-notification-action"]').trigger('click')

    expect(onClick).not.toHaveBeenCalled()
    expect(wrapper.emitted('close')).toBeUndefined()
  })
})
