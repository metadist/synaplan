import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'

import FilePreview from '@/components/files/FilePreview.vue'
import type { FileItem } from '@/services/filesService'

vi.mock('@/services/api/httpClient', () => ({
  getApiBaseUrl: () => 'http://api.test',
}))

vi.mock('@/services/api/mediaAuth', () => ({
  useMediaSrc: () => ({ mediaSrc: (url: string) => url }),
}))

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  missingWarn: false,
  fallbackWarn: false,
  messages: { en: { files: { preview: { play: 'Play' } } } },
})

const file = (overrides: Partial<FileItem>): FileItem =>
  ({
    id: 42,
    filename: 'file.bin',
    path: '/x',
    file_type: '',
    file_size: 0,
    mime: '',
    status: 'ok',
    text_preview: '',
    uploaded_at: 0,
    uploaded_date: '2026-01-01',
    message_id: null,
    ...overrides,
  }) as FileItem

const mountPreview = (f: FileItem, playing = false) =>
  mount(FilePreview, {
    props: { file: f, playing },
    global: {
      plugins: [i18n],
      stubs: {
        Icon: { template: '<i class="icon" />' },
        MessageVideo: { template: '<div class="stub-video" />' },
        MessageAudio: { template: '<div class="stub-audio" />' },
      },
    },
  })

describe('FilePreview lazy playback', () => {
  it('shows a play affordance for audio and mounts no player by default', () => {
    const wrapper = mountPreview(file({ filename: 'song.mp3', origin_kind: 'audio' }))
    expect(wrapper.find('[data-testid="file-preview-audio-play"]').exists()).toBe(true)
    expect(wrapper.find('.stub-audio').exists()).toBe(false)
  })

  it('emits play when the audio affordance is clicked', async () => {
    const wrapper = mountPreview(file({ filename: 'song.mp3', origin_kind: 'audio' }))
    await wrapper.find('[data-testid="file-preview-audio-play"]').trigger('click')
    expect(wrapper.emitted('play')).toHaveLength(1)
  })

  it('mounts the real audio player only once playing', () => {
    const wrapper = mountPreview(file({ filename: 'song.mp3', origin_kind: 'audio' }), true)
    expect(wrapper.find('.stub-audio').exists()).toBe(true)
    expect(wrapper.find('[data-testid="file-preview-audio-play"]').exists()).toBe(false)
  })

  it('shows a poster play overlay for video and no player until tapped', () => {
    const wrapper = mountPreview(file({ filename: 'clip.mp4', origin_kind: 'video' }))
    expect(wrapper.find('[data-testid="file-preview-video-play"]').exists()).toBe(true)
    expect(wrapper.find('.stub-video').exists()).toBe(false)
  })

  it('renders a text snippet for txt files with a preview', () => {
    const wrapper = mountPreview(file({ filename: 'notes.txt', text_preview: 'hello world' }))
    const snippet = wrapper.find('[data-testid="file-preview-snippet"]')
    expect(snippet.exists()).toBe(true)
    expect(snippet.text()).toContain('hello world')
  })

  it('falls back to an icon for documents without extracted text', () => {
    const wrapper = mountPreview(file({ filename: 'deck.pptx' }))
    expect(wrapper.find('[data-testid="file-preview-icon"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="file-preview-snippet"]').exists()).toBe(false)
  })

  it('renders an office poster when thumb_url is set', () => {
    const wrapper = mountPreview(
      file({ filename: 'brief.docx', thumb_url: '/api/v1/files/42/thumb' })
    )
    const poster = wrapper.find('[data-testid="file-preview-document-poster"]')
    expect(poster.exists()).toBe(true)
    expect(poster.find('img').attributes('src')).toBe('http://api.test/api/v1/files/42/thumb')
    expect(wrapper.find('[data-testid="file-preview-icon"]').exists()).toBe(false)
  })

  it('emits preview when the office poster is clicked', async () => {
    const wrapper = mountPreview(
      file({ filename: 'brief.docx', thumb_url: '/api/v1/files/42/thumb' })
    )
    await wrapper.find('[data-testid="file-preview-document-poster"]').trigger('click')
    expect(wrapper.emitted('preview')).toHaveLength(1)
  })

  it('renders a PDF poster when thumb_url is set', () => {
    const wrapper = mountPreview(
      file({ filename: 'report.pdf', thumb_url: '/api/v1/files/42/thumb' })
    )
    expect(wrapper.find('[data-testid="file-preview-document-poster"]').exists()).toBe(true)
  })
})
