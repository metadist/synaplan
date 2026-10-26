import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'

import FileSentTo from '@/components/files/FileSentTo.vue'
import type { FileCopyRow } from '@/services/cloudFolderPushService'

vi.mock('@/composables/useDateFormat', () => ({
  useDateFormat: () => ({
    formatRelativeTime: () => 'just now',
  }),
}))

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      files: {
        push: {
          sentTo: 'Sent to',
          openIn: 'Open in {name}',
          kind: {
            nextcloud: 'Nextcloud',
            opencloud: 'OpenCloud',
            owncloud: 'ownCloud',
          },
          state: {
            delivered: 'Copied',
          },
        },
      },
    },
  },
})

function copyRow(client: string): FileCopyRow {
  return {
    id: 1,
    file_id: 9,
    client,
    host: 'cloud.example.org',
    remote_path: '/Synaplan/Documents/report.pdf',
    state: 'delivered',
    created: 1_700_000_000,
    open_url: 'https://cloud.example.org/f/report.pdf',
    error_code: '',
  }
}

describe('FileSentTo', () => {
  it('shows ownCloud for an owncloud platform client, not the raw slug or Nextcloud', () => {
    const wrapper = mount(FileSentTo, {
      props: { rows: [copyRow('owncloud')] },
      global: { plugins: [i18n] },
    })

    const text = wrapper.text()
    expect(text).toContain('ownCloud · cloud.example.org')
    expect(text).toContain('Open in ownCloud')
    expect(text).not.toContain('Nextcloud')
    expect(text).not.toMatch(/\bowncloud\b/)
  })
})
