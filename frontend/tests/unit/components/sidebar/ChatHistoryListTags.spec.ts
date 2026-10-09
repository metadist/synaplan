import { describe, expect, it, vi, beforeEach } from 'vitest'
import { reactive } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { parseTagLines } from '@/utils/chatTags'
import ChatHistoryList from '@/components/sidebar/ChatHistoryList.vue'
import type { HistoryChat } from '@/composables/useChatHistory'

const updateChatTags = vi.fn()
const prompt = vi.fn()
const success = vi.fn()

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

vi.mock('@/composables/useDialog', () => ({
  useDialog: () => ({ prompt }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({ success, error: vi.fn() }),
}))

vi.mock('@/composables/useChatWelcome', () => ({
  canShareChats: () => false,
  canExportChats: () => false,
}))

vi.mock('@/stores/chats', () => ({
  useChatsStore: () => ({
    updateChatTags,
    pinPendingChatIds: new Set<number>(),
  }),
}))

vi.mock('@/services/api/nativeHaptics', () => ({
  triggerHapticImpact: vi.fn(),
}))

const chat = reactive({
  id: 7,
  title: 'Rent letter',
  tags: ['alpha'],
  incoming: false,
}) as HistoryChat

const mountList = () => {
  document.body.innerHTML = '<div id="app"></div>'
  return mount(ChatHistoryList, {
    attachTo: document.getElementById('app')!,
    props: {
      chats: [chat],
      activeChatId: null,
      titleOf: (row: HistoryChat) => row.title ?? '',
      timeOf: () => '',
      generating: () => false,
    },
    global: {
      mocks: { $t: (key: string) => key },
      stubs: { Icon: true, EllipsisHorizontalIcon: true },
    },
  })
}

describe('parseTagLines', () => {
  it('keeps one trimmed tag per line and drops blanks', () => {
    expect(parseTagLines('alpha\n\n beta \nalpha')).toEqual(['alpha', 'beta'])
  })

  it('returns an empty list when every line is blank', () => {
    expect(parseTagLines('\n  \n')).toEqual([])
  })
})

describe('ChatHistoryList tags', () => {
  beforeEach(() => {
    chat.tags = ['alpha']
    updateChatTags.mockReset()
    prompt.mockReset()
    success.mockReset()
    updateChatTags.mockImplementation(async () => {
      chat.tags = ['alpha', 'beta']
      return chat.tags
    })
    prompt.mockResolvedValue('alpha\nbeta')
  })

  it('shows Tags in the row menu and saves the parsed list', async () => {
    const wrapper = mountList()
    expect(wrapper.find('[data-testid="text-chat-tag"]').text()).toBe('alpha')

    await wrapper.get('[data-testid="btn-chat-v2-row-menu"]').trigger('click')
    const tags = document.querySelector('[data-testid="btn-chat-v2-tags"]')
    expect(tags).toBeTruthy()
    tags?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(prompt).toHaveBeenCalledWith(
      expect.objectContaining({
        title: 'chat.tags',
        message: 'chat.tagsHint',
        defaultValue: 'alpha',
        multiline: true,
      })
    )
    expect(updateChatTags).toHaveBeenCalledWith(7, ['alpha', 'beta'])
    expect(success).toHaveBeenCalledWith('chat.tagsSaved')
    await flushPromises()
    const shown = wrapper.findAll('[data-testid="text-chat-tag"]').map((node) => node.text())
    expect(shown).toEqual(['alpha', 'beta'])
    wrapper.unmount()
  })

  it('removes the tags when the server stores an empty list', async () => {
    updateChatTags.mockImplementation(async () => {
      chat.tags = []
      return []
    })
    prompt.mockResolvedValue('\n')
    const wrapper = mountList()

    await wrapper.get('[data-testid="btn-chat-v2-row-menu"]').trigger('click')
    document
      .querySelector('[data-testid="btn-chat-v2-tags"]')
      ?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(updateChatTags).toHaveBeenCalledWith(7, [])
    expect(wrapper.find('[data-testid="list-chat-tags"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
