import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import WhatWasSentDialog from '@/components/chat/WhatWasSentDialog.vue'

const mountDialog = (props: {
  messages: Array<{ role: string; text: string }> | null
  truncated?: boolean
}) =>
  mount(WhatWasSentDialog, {
    props: { messages: props.messages, truncated: props.truncated ?? false },
  })

describe('WhatWasSentDialog', () => {
  it('shows the three roles and the session sentence', () => {
    const wrapper = mountDialog({
      messages: [
        { role: 'instructions', text: 'Be brief.' },
        { role: 'you', text: 'Hello' },
        { role: 'assistant', text: 'Hi' },
      ],
    })

    expect(wrapper.text()).toContain('Instructions')
    expect(wrapper.text()).toContain('You')
    expect(wrapper.text()).toContain('Assistant')
    expect(wrapper.text()).toContain('Be brief.')
    expect(wrapper.text()).toContain('This stays on this screen until you leave the chat.')
    expect(wrapper.find('[data-testid="text-what-was-sent-empty"]').exists()).toBe(false)
  })

  it('shows the empty sentence when nothing was recorded', () => {
    const wrapper = mountDialog({ messages: [] })

    expect(wrapper.get('[data-testid="text-what-was-sent-empty"]').text()).toContain(
      'Nothing was recorded'
    )
    expect(wrapper.text()).not.toContain('Instructions')
  })

  it('shows the truncation notice when truncated is true and closes on Escape', async () => {
    const wrapper = mountDialog({
      messages: [{ role: 'you', text: 'Long input' }],
      truncated: true,
    })

    expect(wrapper.get('[data-testid="text-what-was-sent-truncated"]').text()).toContain(
      'The rest is left out'
    )

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    expect(wrapper.emitted('close')).toHaveLength(1)
    wrapper.unmount()
  })
})
