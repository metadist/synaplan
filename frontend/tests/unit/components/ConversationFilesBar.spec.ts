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
          count: '{count} file | {count} files',
          delete: 'Remove {name} from this chat',
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

const annex: ConversationFileRow = {
  ...contract,
  id: 78,
  reference: 'file:78',
  name: 'annex.pdf',
}

const mountBar = (
  files: ConversationFileRow[] = [contract],
  canAttach = true,
  canDelete = false
) =>
  mount(ConversationFilesBar, {
    props: { files, canAttach, canDelete },
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

  it('shows a compact toggle with the file count', () => {
    const wrapper = mountBar()

    const toggle = wrapper.get('[data-testid="conversation-files-toggle"]')
    expect(toggle.text()).toContain('1')
    expect(toggle.attributes('aria-expanded')).toBe('false')
  })

  it('reveals the files on toggle and emits attach on click', async () => {
    const wrapper = mountBar()

    // v-show keeps the popover mounted, so presence alone proves nothing. The
    // toggle's aria-expanded state is the deterministic signal that it opened
    // (and it is what assistive tech reads).
    const toggle = wrapper.get('[data-testid="conversation-files-toggle"]')
    const popover = wrapper.get('[data-testid="conversation-files-popover"]')
    expect(toggle.attributes('aria-expanded')).toBe('false')

    await toggle.trigger('click')

    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(popover.text()).toContain('Files in this chat')

    const chip = wrapper.get('[data-testid="conversation-file-chip"]')
    expect(chip.text()).toContain('contract.pdf')

    await chip.trigger('click')

    expect(wrapper.emitted('preview')?.[0]).toEqual([contract])
    expect(wrapper.emitted('attach')).toBeUndefined()

    await wrapper.get('[data-testid="conversation-file-reattach"]').trigger('click')

    expect(wrapper.emitted('attach')?.[0]).toEqual([contract])
    // Re-attaching closes the popover again.
    expect(toggle.attributes('aria-expanded')).toBe('false')
  })

  it('does not emit attach when the surface is read-only', async () => {
    const wrapper = mountBar([contract], false)

    await wrapper.get('[data-testid="conversation-files-toggle"]').trigger('click')
    await wrapper.get('[data-testid="conversation-file-chip"]').trigger('click')

    expect(wrapper.emitted('attach')).toBeUndefined()
  })

  it('removes a file from the open list without closing it', async () => {
    const wrapper = mountBar([contract, annex], true, true)

    const toggle = wrapper.get('[data-testid="conversation-files-toggle"]')
    await toggle.trigger('click')
    expect(toggle.text()).toContain('2')

    const remove = wrapper.get('[data-testid="conversation-file-remove"]')
    await remove.trigger('click')

    expect(wrapper.emitted('remove')?.[0]).toEqual([contract])
    expect(toggle.attributes('aria-expanded')).toBe('true')

    await wrapper.setProps({ files: [annex] })

    expect(toggle.text()).toContain('1')
    expect(wrapper.text()).not.toContain('contract.pdf')
    expect(wrapper.text()).toContain('annex.pdf')
  })
})
