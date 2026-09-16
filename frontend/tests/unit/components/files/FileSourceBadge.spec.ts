import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'

import FileSourceBadge from '@/components/files/FileSourceBadge.vue'
import type { FileSource } from '@/services/filesService'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      files: {
        help: { source: 'Where this file came from.' },
        sourceLabel: {
          web_upload: 'Upload',
          generated: 'AI-generated',
          whatsapp: 'WhatsApp',
        },
      },
    },
  },
})

function mountBadge(source?: FileSource) {
  return mount(FileSourceBadge, {
    props: source ? { source } : {},
    global: {
      plugins: [i18n],
      stubs: { Icon: { template: '<i />' } },
    },
  })
}

describe('FileSourceBadge', () => {
  it('hides the default upload origin — it does not distinguish a file', () => {
    const wrapper = mountBadge('web_upload')
    expect(wrapper.find('[data-testid="file-source-badge"]').exists()).toBe(false)
    expect(wrapper.text()).toBe('')
  })

  it('hides when source is omitted (defaults to web_upload)', () => {
    const wrapper = mountBadge()
    expect(wrapper.find('[data-testid="file-source-badge"]').exists()).toBe(false)
  })

  it('shows a distinctive origin', () => {
    const wrapper = mountBadge('generated')
    expect(wrapper.find('[data-testid="file-source-badge"]').exists()).toBe(true)
    expect(wrapper.text()).toBe('AI-generated')
  })
})
