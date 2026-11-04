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
          processing: 'Reading…',
          none: 'Not searchable',
          notApplicable: 'Not applicable',
          failed: "Couldn't be read",
        },
        help: {
          vectorized: 'Its contents are in your knowledge base.',
          notSearchable: 'The assistant cannot use this file yet.',
          processing: 'This file is being prepared for the assistant.',
          failed: 'This file could not be made searchable.',
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

function pill(wrapper: ReturnType<typeof mountPill>) {
  return wrapper.find('[data-testid="file-vector-pill"]')
}

describe('FileVectorPill', () => {
  it('shows the searchable label as visible text, not only a tooltip', () => {
    const wrapper = mountPill({ state: 'vectorized', chunkCount: 12, groupKey: 'Contracts' })
    expect(wrapper.text()).toContain('Searchable by AI')
    const el = pill(wrapper)
    expect(el.classes().join(' ')).toContain('status-success-text')
    expect(el.attributes('title')).toBe('Searchable by AI · Contracts · 12 chunks')
  })

  it('falls back to the plain help tooltip when there is no group', () => {
    const el = pill(mountPill({ state: 'vectorized', chunkCount: 0, groupKey: null }))
    expect(el.attributes('title')).toBe('Its contents are in your knowledge base.')
  })

  it('renders the reading state as a word', () => {
    const wrapper = mountPill({ state: 'pending' })
    expect(wrapper.text()).toContain('Reading…')
    expect(pill(wrapper).classes().join(' ')).toContain('status-info')
  })

  it('renders the failed state as a word', () => {
    const wrapper = mountPill({ state: 'failed' })
    expect(wrapper.text()).toContain("Couldn't be read")
    expect(pill(wrapper).classes().join(' ')).toContain('status-error')
  })

  it('renders not-searchable for the legacy not-applicable state', () => {
    const wrapper = mountPill({ state: 'not_applicable' })
    expect(wrapper.text()).toContain('Not searchable')
    expect(pill(wrapper).classes()).toContain('txt-secondary')
  })

  it('renders not-searchable when the file is not in the knowledge base', () => {
    const wrapper = mountPill()
    expect(wrapper.text()).toContain('Not searchable')
    expect(pill(wrapper).attributes('title')).toBe('The assistant cannot use this file yet.')
  })

  it('can shrink instead of wrapping the row', () => {
    const classes = pill(mountPill({ state: 'vectorized' })).classes()
    expect(classes).toContain('min-w-0')
    expect(classes).toContain('max-w-full')
    expect(classes).toContain('overflow-hidden')
  })
})
