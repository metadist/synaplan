import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import MessageMoreActions from '@/components/chat/MessageMoreActions.vue'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  missingWarn: false,
  fallbackWarn: false,
  messages: { en: {} },
})

function mountMenu() {
  return mount(MessageMoreActions, {
    props: { showFalsePositive: true, showReport: true, falsePositiveDisabled: false },
    attachTo: document.body,
    global: { plugins: [i18n] },
  })
}

describe('MessageMoreActions', () => {
  it('closes on Escape and on a press outside the menu', async () => {
    const wrapper = mountMenu()
    await wrapper.get('[data-testid="btn-message-more"]').trigger('click')
    expect(wrapper.find('[data-testid="dropdown-message-more"]').exists()).toBe(true)

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="dropdown-message-more"]').exists()).toBe(false)

    await wrapper.get('[data-testid="btn-message-more"]').trigger('click')
    document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="dropdown-message-more"]').exists()).toBe(false)

    wrapper.unmount()
  })
})
