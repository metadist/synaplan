import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ToolBadge from '@/components/ToolBadge.vue'

describe('ToolBadge', () => {
  it('renders the localized label and variant class per tool', () => {
    const cases = [
      { tool: 'search' as const, variant: 'tool-badge--search', label: 'Search' },
      { tool: 'pic' as const, variant: 'tool-badge--image', label: 'Image' },
      { tool: 'vid' as const, variant: 'tool-badge--video', label: 'Video' },
    ]
    for (const { tool, variant, label } of cases) {
      const wrapper = mount(ToolBadge, { props: { tool } })
      const chip = wrapper.get('[data-testid="chip-active-tool"]')
      expect(chip.classes()).toContain(variant)
      expect(chip.text()).toContain(label)
    }
  })

  it('emits remove when clicked', async () => {
    const wrapper = mount(ToolBadge, { props: { tool: 'search' } })
    await wrapper.get('[data-testid="chip-active-tool"]').trigger('click')
    expect(wrapper.emitted('remove')).toBeTruthy()
  })
})
