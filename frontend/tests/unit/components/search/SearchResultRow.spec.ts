import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { FolderIcon } from '@heroicons/vue/24/outline'
import SearchResultRow from '@/components/search/SearchResultRow.vue'
import type { SearchResult } from '@/composables/search/types'

const base: SearchResult = {
  id: 'file:1',
  kind: 'file',
  title: 'Invoice 2026.pdf',
  subtitle: 'Sources › Invoices',
  snippet: 'total amount due',
  icon: FolderIcon,
  matchedBy: 'lexical',
}

describe('SearchResultRow', () => {
  it('renders an option with title, breadcrumb and snippet', () => {
    const wrapper = mount(SearchResultRow, {
      props: { result: base, active: true, optionId: 'opt-0' },
    })
    const option = wrapper.get('[role="option"]')
    expect(option.attributes('id')).toBe('opt-0')
    expect(option.attributes('aria-selected')).toBe('true')
    expect(option.text()).toContain('Invoice 2026.pdf')
    expect(option.text()).toContain('Sources › Invoices')
    expect(option.text()).toContain('total amount due')
  })

  it('marks results that were only found by meaning', () => {
    const lexical = mount(SearchResultRow, {
      props: { result: base, active: false, optionId: 'opt-1' },
    })
    expect(lexical.find('[data-testid="badge-smart-search-semantic"]').exists()).toBe(false)

    const semantic = mount(SearchResultRow, {
      props: { result: { ...base, matchedBy: 'semantic' }, active: false, optionId: 'opt-2' },
    })
    expect(semantic.find('[data-testid="badge-smart-search-semantic"]').exists()).toBe(true)
  })

  it('emits select on click', async () => {
    const wrapper = mount(SearchResultRow, {
      props: { result: base, active: false, optionId: 'opt-3' },
    })
    await wrapper.get('[role="option"]').trigger('click')
    expect(wrapper.emitted('select')).toHaveLength(1)
  })
})
