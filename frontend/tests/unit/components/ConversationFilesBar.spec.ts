import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'

import ConversationFilesBar from '@/components/chat/ConversationFilesBar.vue'
import type { ConversationFileRow } from '@/composables/useConversationFiles'

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  messages: {
    en: {
      chat: {
        conversationFiles: {
          title: 'Files in this chat',
          hint: 'Questions you ask still use these files.',
          attach: 'Use {name} in the next message',
          attached: '{name} will be sent with your next message',
        },
      },
    },
  },
})

const contract: ConversationFileRow = {
  id: 77,
  reference: 'file:77',
  name: 'contract.pdf',
  category: 'document',
  origin: 'uploaded',
  fileType: 'pdf',
  messageId: 100,
  hasText: true,
}

const mountBar = (files: ConversationFileRow[] = [contract], canAttach = true) =>
  mount(ConversationFilesBar, {
    props: { files, canAttach },
    global: {
      plugins: [i18n],
      stubs: { Icon: true },
    },
  })

describe('ConversationFilesBar', () => {
  it('does not render when the chat has no files', () => {
    const wrapper = mountBar([])

    expect(wrapper.find('[data-testid="conversation-files-bar"]').exists()).toBe(false)
  })

  it('lists conversation files and emits attach on click', async () => {
    const wrapper = mountBar()

    expect(wrapper.get('[data-testid="conversation-files-bar"]').text()).toContain(
      'Files in this chat'
    )
    expect(wrapper.get('[data-testid="conversation-file-chip"]').text()).toContain('contract.pdf')

    await wrapper.get('[data-testid="conversation-file-chip"]').trigger('click')

    expect(wrapper.emitted('attach')?.[0]).toEqual([contract])
  })

  it('does not emit attach when the surface is read-only', async () => {
    const wrapper = mountBar([contract], false)

    await wrapper.get('[data-testid="conversation-file-chip"]').trigger('click')

    expect(wrapper.emitted('attach')).toBeUndefined()
  })
})
