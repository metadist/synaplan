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
    expect(header.classes()).toContain('stack-row')
    expect(header.element.parentElement?.parentElement?.classList.contains('hover-surface')).toBe(
      true
    )
    const body = wrapper.get('#config-section-cloud-body')
    expect(body.classes()).toContain('p-5')
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

  it('keeps the row highlight behind an action button', () => {
    const wrapper = mount(AccordionSection, {
      props: {
        panelId: 'prompt-section-1',
        title: 'general',
        open: true,
        headerTestid: 'btn-prompt-section-1',
      },
      slots: {
        actions: '<button type="button" class="btn-secondary" data-testid="btn-edit">Edit</button>',
        default: '<p>Prompt text</p>',
      },
    })

    const header = wrapper.get('[data-testid="btn-prompt-section-1"]')
    const row = header.element.parentElement?.parentElement
    expect(row?.classList.contains('accordion-row')).toBe(true)
    expect(row?.classList.contains('hover-surface')).toBe(true)
    expect(row?.contains(wrapper.get('[data-testid="btn-edit"]').element)).toBe(true)
    expect(header.classes()).not.toContain('hover-surface')
  })
})
