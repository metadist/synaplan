import { afterEach, describe, expect, it } from 'vitest'
import { mount, type VueWrapper } from '@vue/test-utils'
import WhatWasSentDialog from '@/components/chat/WhatWasSentDialog.vue'

let wrapper: VueWrapper | undefined

afterEach(() => {
  wrapper?.unmount()
  wrapper = undefined
  document.body.style.overflow = ''
})

const mountDialog = (props: {
  messages: Array<{ role: string; text: string }> | null
  truncated?: boolean
}) => {
  wrapper = mount(WhatWasSentDialog, {
    props: { messages: props.messages, truncated: props.truncated ?? false },
    global: {
      stubs: {
        Teleport: { template: '<div><slot /></div>' },
      },
    },
  })
  return wrapper
}

describe('WhatWasSentDialog', () => {
  it('shows the three roles and the session sentence', () => {
    const view = mountDialog({
      messages: [
        { role: 'instructions', text: 'Be brief.' },
        { role: 'you', text: 'Hello' },
        { role: 'assistant', text: 'Hi' },
      ],
    })

    expect(view.text()).toContain('Instructions')
    expect(view.text()).toContain('You')
    expect(view.text()).toContain('Assistant')
    expect(view.text()).toContain('Be brief.')
    expect(view.text()).toContain('This stays on this screen until you leave the chat.')
    expect(view.find('[data-testid="text-what-was-sent-empty"]').exists()).toBe(false)
  })

  it('shows the empty sentence when nothing was recorded', () => {
    const view = mountDialog({ messages: [] })

    expect(view.get('[data-testid="text-what-was-sent-empty"]').text()).toContain(
      'Nothing was recorded'
    )
    expect(view.text()).not.toContain('Instructions')
  })

  it('shows the truncation notice when truncated is true and closes on Escape', async () => {
    const view = mountDialog({
      messages: [{ role: 'you', text: 'Long input' }],
      truncated: true,
    })

    expect(view.get('[data-testid="text-what-was-sent-truncated"]').text()).toContain(
      'The rest is left out'
    )

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    expect(view.emitted('close')).toHaveLength(1)
  })

  it('renders the instructions as markdown and keeps the question apart from earlier messages', () => {
    const view = mountDialog({
      messages: [
        { role: 'instructions', text: '# Rules\n\nBe **brief**.' },
        { role: 'you', text: 'Earlier question' },
        { role: 'assistant', text: 'Earlier answer' },
        { role: 'you', text: 'Latest question' },
      ],
    })

    const question = view.get('[data-testid="section-what-was-sent-question"]')
    expect(question.text()).toContain('Your question')
    expect(question.text()).toContain('Latest question')
    expect(question.text()).not.toContain('Earlier question')

    const history = view.get('[data-testid="section-what-was-sent-history"]')
    expect(history.text()).toContain('Earlier messages')
    expect(history.text()).toContain('Earlier question')
    expect(history.text()).toContain('Earlier answer')
    expect(history.text()).toContain('You')
    expect(history.text()).toContain('Assistant')

    const instructions = view.get('[data-testid="section-what-was-sent-instructions"]')
    expect(instructions.get('h1').text()).toBe('Rules')
    expect(instructions.get('strong').text()).toBe('brief')
    expect(instructions.text()).not.toContain('# Rules')
  })

  it('shows the latest request and keeps earlier steps collapsed', () => {
    const view = mountDialog({
      messages: [
        { role: 'instructions', text: 'Edit the sheet.' },
        { role: 'you', text: 'Add a total row.' },
        { role: 'instructions', text: 'Edit the sheet.' },
        { role: 'you', text: 'Add a total row.' },
        { role: 'assistant', text: 'Updated A10' },
      ],
    })

    expect(view.get('[data-testid="section-what-was-sent-after"]').text()).toContain('Updated A10')
    const details = view.get('[data-testid="details-what-was-sent-earlier"]')
    expect(details.attributes('open')).toBeUndefined()
    expect(details.text()).toContain('1 earlier step')
    expect(details.text()).toContain('Step 1')
  })
})
