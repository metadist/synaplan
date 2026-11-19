import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ProjectSourceRow from '@/components/projects/ProjectSourceRow.vue'
import type { ProjectSourceItem } from '@/services/api/projectsApi'

const messages = {
  en: {
    projects: {
      source: {
        reading: 'Reading…',
        ready: 'Ready',
        failed: 'Could not be read',
        retry: 'Try again',
        remove: 'Remove',
      },
    },
  },
}

function source(overrides: Partial<ProjectSourceItem> = {}): ProjectSourceItem {
  return {
    id: 7,
    name: 'Offer.pdf',
    kind: 'file',
    state: 'ready',
    reason: null,
    sourceUrl: null,
    fetchedAt: null,
    size: 2048,
    createdAt: 1,
    ...overrides,
  }
}

function mountRow(item: ProjectSourceItem) {
  const i18n = createI18n({ legacy: false, locale: 'en', messages })
  return mount(ProjectSourceRow, {
    props: { source: item },
    global: { plugins: [i18n], stubs: { Icon: true } },
  })
}

describe('ProjectSourceRow', () => {
  it('shows "Reading…" while a source is read, with no retry', () => {
    const wrapper = mountRow(source({ state: 'reading', size: 0 }))
    expect(wrapper.find('[data-testid="text-project-source-reading"]').text()).toContain('Reading…')
    expect(wrapper.find('[data-testid="btn-project-source-retry"]').exists()).toBe(false)
  })

  it('shows "Ready" with the size', () => {
    const wrapper = mountRow(source())
    const text = wrapper.find('[data-testid="text-project-source-ready"]').text()
    expect(text).toContain('Ready')
    expect(text).toContain('2.0 KB')
  })

  it('shows the reason and "Try again" for a failed link, and emits retry', async () => {
    const wrapper = mountRow(
      source({
        kind: 'link',
        state: 'failed',
        reason: 'HTTP 403',
        sourceUrl: 'https://example.com/offer',
      })
    )
    const failed = wrapper.find('[data-testid="text-project-source-failed"]')
    expect(failed.text()).toContain('Could not be read')
    expect(failed.text()).toContain('HTTP 403')
    await wrapper.find('[data-testid="btn-project-source-retry"]').trigger('click')
    expect(wrapper.emitted('retry')).toEqual([[7]])
  })

  it('offers no retry for a failed file, only remove', async () => {
    const wrapper = mountRow(source({ state: 'failed', reason: 'Unsupported file' }))
    expect(wrapper.find('[data-testid="btn-project-source-retry"]').exists()).toBe(false)
    await wrapper.find('[data-testid="btn-project-source-remove"]').trigger('click')
    expect(wrapper.emitted('remove')).toEqual([[7]])
  })

  it('does not turn a non-web address into a link', () => {
    const wrapper = mountRow(
      source({ kind: 'link', sourceUrl: 'javascript:alert(1)', state: 'ready' })
    )
    expect(wrapper.find('[data-testid="link-project-source-url"]').exists()).toBe(false)
  })

  it('links a web address safely', () => {
    const wrapper = mountRow(source({ kind: 'link', sourceUrl: 'https://example.com/a' }))
    const a = wrapper.find('[data-testid="link-project-source-url"]')
    expect(a.attributes('href')).toBe('https://example.com/a')
    expect(a.attributes('rel')).toContain('noopener')
  })
})
