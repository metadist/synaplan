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
  it('is icon-only (no visible text label)', () => {
    const wrapper = mountPill({ state: 'vectorized', chunkCount: 12, groupKey: 'Contracts' })
    expect(wrapper.text()).toBe('')
  })

  it('shows green + the searchable label with group + chunk detail in the tooltip', () => {
    const el = pill(mountPill({ state: 'vectorized', chunkCount: 12, groupKey: 'Contracts' }))
    expect(el.classes()).toContain('text-emerald-600')
    expect(el.attributes('aria-label')).toBe('Searchable by AI')
    expect(el.attributes('title')).toBe('Searchable by AI · Contracts · 12 chunks')
  })

  it('falls back to the plain help tooltip when there is no group', () => {
    const el = pill(mountPill({ state: 'vectorized', chunkCount: 0, groupKey: null }))
    expect(el.attributes('title')).toBe('Its contents are in your knowledge base.')
  })

  it('renders the processing state', () => {
    const el = pill(mountPill({ state: 'pending' }))
    expect(el.classes()).toContain('text-blue-500')
    expect(el.attributes('aria-label')).toBe('Processing…')
  })

  it('renders the failed state', () => {
    const el = pill(mountPill({ state: 'failed' }))
    expect(el.classes()).toContain('text-red-500')
    expect(el.attributes('aria-label')).toBe('Failed')
  })

  it('renders grey not-searchable for the legacy not-applicable state', () => {
    const el = pill(mountPill({ state: 'not_applicable' }))
    expect(el.exists()).toBe(true)
    expect(el.classes()).toContain('txt-secondary')
    expect(el.attributes('aria-label')).toBe('Not searchable')
  })

  it('renders grey not-searchable when the file is not in the knowledge base', () => {
    const el = pill(mountPill())
    expect(el.exists()).toBe(true)
    expect(el.classes()).toContain('txt-secondary')
    expect(el.attributes('aria-label')).toBe('Not searchable')
    expect(el.attributes('title')).toBe('The assistant cannot use this file yet.')
  })
})
