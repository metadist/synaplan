import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'

import FileVectorPill from '@/components/files/FileVectorPill.vue'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      files: {
        vectorState: {
          vectorized: 'Searchable by AI',
          vectorizedDetail: 'Searchable by AI · {group} · {count} chunks',
          processing: 'Processing…',
          none: 'Not searchable',
          notApplicable: 'Not applicable',
          failed: 'Failed',
        },
        help: {
          vectorized: 'Its contents are in your knowledge base.',
        },
      },
    },
  },
})

function mountPill(props: Record<string, unknown> = {}) {
  return mount(FileVectorPill, {
    props,
    global: {
      plugins: [i18n],
      stubs: {
        Icon: { template: '<i />' },
      },
    },
  })
}

describe('FileVectorPill', () => {
  it('renders the searchable label with group + chunk detail', () => {
    const wrapper = mountPill({ state: 'vectorized', chunkCount: 12, groupKey: 'Contracts' })
    expect(wrapper.text()).toBe('Searchable by AI · Contracts · 12 chunks')
  })

  it('renders the plain searchable label without a group', () => {
    const wrapper = mountPill({ state: 'vectorized', chunkCount: 0, groupKey: null })
    expect(wrapper.text()).toBe('Searchable by AI')
  })

  it('renders the processing state', () => {
    const wrapper = mountPill({ state: 'pending' })
    expect(wrapper.text()).toBe('Processing…')
  })

  it('renders the failed state', () => {
    const wrapper = mountPill({ state: 'failed' })
    expect(wrapper.text()).toBe('Failed')
  })

  it('renders nothing for the legacy not-applicable state', () => {
    // Media is no longer "not applicable" — any stray legacy value renders
    // nothing, just like the not-searchable state.
    const wrapper = mountPill({ state: 'not_applicable' })
    expect(wrapper.find('[data-testid="file-vector-pill"]').exists()).toBe(false)
    expect(wrapper.text()).toBe('')
  })

  it('renders nothing when the file is not searchable', () => {
    const wrapper = mountPill()
    expect(wrapper.find('[data-testid="file-vector-pill"]').exists()).toBe(false)
    expect(wrapper.text()).toBe('')
  })
})
