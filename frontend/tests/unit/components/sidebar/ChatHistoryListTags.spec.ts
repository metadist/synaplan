import { describe, expect, it, vi, beforeEach } from 'vitest'
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

const chat = {
  id: 7,
  title: 'Rent letter',
  tags: ['alpha'],
  incoming: false,
} as HistoryChat

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
    updateChatTags.mockReset()
    prompt.mockReset()
    success.mockReset()
    updateChatTags.mockResolvedValue(['alpha', 'beta'])
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
        defaultValue: 'alpha',
        multiline: true,
      })
    )
    expect(updateChatTags).toHaveBeenCalledWith(7, ['alpha', 'beta'])
    expect(success).toHaveBeenCalledWith('chat.tagsSaved')
    wrapper.unmount()
  })
})
