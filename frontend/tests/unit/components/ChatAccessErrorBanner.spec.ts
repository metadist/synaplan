import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import ChatAccessErrorBanner from '@/components/ChatAccessErrorBanner.vue'

describe('ChatAccessErrorBanner', () => {
  it('explains the missing composer and offers a retry', async () => {
    const wrapper = mount(ChatAccessErrorBanner, { props: { retrying: false } })

    expect(wrapper.get('[data-testid="banner-chat-access-failed"]').attributes('role')).toBe(
      'alert'
    )
    await wrapper.get('[data-testid="btn-chat-access-retry"]').trigger('click')

    expect(wrapper.emitted('retry')).toHaveLength(1)
  })

  it('disables the button while a retry is running', () => {
    const wrapper = mount(ChatAccessErrorBanner, { props: { retrying: true } })

    expect(
      wrapper.get('[data-testid="btn-chat-access-retry"]').attributes('disabled')
    ).toBeDefined()
  })
})
