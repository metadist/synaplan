import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import QuoteSelectionButton from '@/components/QuoteSelectionButton.vue'

const findButton = () =>
  document.body.querySelector<HTMLButtonElement>('[data-testid="btn-quote-selection"]')

afterEach(() => {
  document.body.innerHTML = ''
})

describe('QuoteSelectionButton', () => {
  it('does not render when not visible', () => {
    mount(QuoteSelectionButton, {
      props: { visible: false, position: { top: 100, bottom: 120, left: 200 } },
    })
    expect(findButton()).toBeNull()
  })

  it('renders centered above the selection when there is room', () => {
    mount(QuoteSelectionButton, {
      props: { visible: true, position: { top: 300, bottom: 320, left: 200 } },
    })
    const button = findButton()
    expect(button).not.toBeNull()
    expect(button?.style.left).toBe('200px')
    // 300 (top) - 8 (gap)
    expect(button?.style.top).toBe('292px')
    expect(button?.style.transform).toBe('translate(-50%, -100%)')
  })

  it('flips below the selection when too close to the viewport top', () => {
    mount(QuoteSelectionButton, {
      props: { visible: true, position: { top: 10, bottom: 40, left: 150 } },
    })
    const button = findButton()
    // 40 (bottom) + 8 (gap)
    expect(button?.style.top).toBe('48px')
    expect(button?.style.transform).toBe('translate(-50%, 0)')
  })

  it('emits quote when clicked', async () => {
    const wrapper = mount(QuoteSelectionButton, {
      props: { visible: true, position: { top: 300, bottom: 320, left: 200 } },
    })
    findButton()?.click()
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('quote')).toBeTruthy()
  })
})
