import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AccordionSection from '@/components/AccordionSection.vue'

describe('AccordionSection', () => {
  it('exposes the header as a button and hides the body when closed', async () => {
    const wrapper = mount(AccordionSection, {
      props: {
        panelId: 'config-section-cloud',
        title: 'Cloud AI Providers',
        open: false,
        headerTestid: 'btn-config-section-cloud',
      },
      slots: {
        default: '<p>Section body</p>',
      },
    })

    const header = wrapper.get('[data-testid="btn-config-section-cloud"]')
    const body = wrapper.get('#config-section-cloud-body')
    expect(header.attributes('aria-expanded')).toBe('false')
    expect(body.attributes('style') ?? '').toContain('display: none')
    expect(wrapper.get('#config-section-cloud').attributes('data-open')).toBe('false')

    await wrapper.setProps({ open: true })
    expect(header.attributes('aria-expanded')).toBe('true')
    expect(body.attributes('style') ?? '').not.toContain('display: none')
    expect(wrapper.get('#config-section-cloud').attributes('data-open')).toBe('true')
  })

  it('emits toggle when the header is clicked', async () => {
    const wrapper = mount(AccordionSection, {
      props: {
        panelId: 'section-a',
        title: 'Section A',
        open: true,
        headerTestid: 'btn-section-a',
      },
    })

    await wrapper.get('[data-testid="btn-section-a"]').trigger('click')
    expect(wrapper.emitted('toggle')).toHaveLength(1)
  })
})
