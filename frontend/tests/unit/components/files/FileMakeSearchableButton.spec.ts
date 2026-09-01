import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'

import FileMakeSearchableButton from '@/components/files/FileMakeSearchableButton.vue'
import type { FileItem } from '@/services/filesService'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      files: {
        describeSortAction: 'Make searchable by AI',
      },
    },
  },
})

const file = (overrides: Partial<FileItem>): FileItem =>
  ({
    id: 1,
    filename: 'recording.webm',
    path: '/x',
    file_type: 'webm',
    file_size: 1,
    mime: 'audio/webm',
    status: 'uploaded',
    text_preview: '',
    uploaded_at: 0,
    uploaded_date: '2026-08-20 12:40:41',
    message_id: null,
    vector_state: 'none',
    ...overrides,
  }) as FileItem

function mountButton(overrides: Partial<FileItem> = {}, busy = false) {
  return mount(FileMakeSearchableButton, {
    props: { file: file(overrides), busy },
    global: {
      plugins: [i18n],
      stubs: { Icon: { template: '<i />' } },
    },
  })
}

describe('FileMakeSearchableButton', () => {
  it('is hidden when the file is already searchable', () => {
    const wrapper = mountButton({ vector_state: 'vectorized' })
    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('uses the same label for uploads and generated files', () => {
    const upload = mountButton({ source: 'web_upload' })
    const generated = mountButton({ source: 'generated' })
    expect(upload.find('button').attributes('title')).toBe('Make searchable by AI')
    expect(generated.find('button').attributes('title')).toBe('Make searchable by AI')
    expect(upload.find('button').attributes('data-testid')).toBe('btn-describe')
    expect(generated.find('button').attributes('data-testid')).toBe('btn-index-prompt')
  })

  it('emits activate when clicked', async () => {
    const wrapper = mountButton()
    await wrapper.find('button').trigger('click')
    expect(wrapper.emitted('activate')).toHaveLength(1)
  })
})
