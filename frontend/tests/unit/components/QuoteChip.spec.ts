import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import QuoteChip from '@/components/QuoteChip.vue'

describe('QuoteChip', () => {
  it('renders the quoted text', () => {
    const wrapper = mount(QuoteChip, {
      props: { quote: { text: 'A quoted excerpt', messageId: 42, role: 'assistant' } },
    })
    expect(wrapper.get('[data-testid="chip-quote"]').text()).toContain('A quoted excerpt')
  })

  it('emits remove when the remove button is clicked', async () => {
    const wrapper = mount(QuoteChip, {
      props: { quote: { text: 'something' } },
    })
    await wrapper.get('[data-testid="btn-quote-remove"]').trigger('click')
    expect(wrapper.emitted('remove')).toBeTruthy()
  })
})
