import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import type { Widget } from '@/services/api/widgetsApi'

const api = vi.hoisted(() => ({
  listWidgetSessions: vi.fn(),
  getWidgetSession: vi.fn(),
  takeOverSession: vi.fn(),
  sendHumanMessage: vi.fn(),
  handBackSession: vi.fn(),
}))
const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('@/services/api/widgetSessionsApi', () => api)
vi.mock('@/composables/useNotification', () => ({ useNotification: () => notify }))
vi.mock('@/services/realtime/widgetOperatorRealtime', () => ({
  subscribeToWidgetOperatorChannel: () => ({ unsubscribe: vi.fn() }),
}))

import WidgetConversations from '@/components/widgets/WidgetConversations.vue'

const messages = {
  en: {
    common: { retry: 'Retry' },
    liveSupport: {
      title: 'Conversations',
      subtitle: 'Visitors wait here.',
      allWidgets: 'All widgets',
      waiting: 'Waiting for you',
      myChats: 'My chats',
      active: 'Active',
      noSessions: 'No visitor is waiting.',
      noMine: 'You have not taken over a conversation.',
      noMessages: 'No messages yet',
      loadFailed: 'Conversations could not be loaded.',
      sendFailed: 'Your reply was not sent and the AI is still answering.',
      sendFailedTakenOver: 'You took over the conversation, but your reply was not sent.',
      selectSession: 'Select a conversation',
      chatWith: 'Chat with',
      messagesCount: 'messages',
      typePlaceholder: 'Type…',
      send: 'Send reply',
      backToList: 'Back',
      visitor: 'Visitor',
      you: 'You',
      messageSent: 'Sent',
    },
  },
}

const widget = { widgetId: 'w1', name: 'Shop' } as Widget

const session = (id: number, mode: 'waiting' | 'human') => ({
  id,
  sessionId: `s${id}`,
  mode,
  lastMessage: 1000 + id,
  lastMessagePreview: `preview ${id}`,
  messageCount: 2,
})

function mountView() {
  return mount(WidgetConversations, {
    props: { widgets: [widget] },
    global: {
      plugins: [createI18n({ legacy: false, locale: 'en', messages })],
      stubs: {
        Icon: true,
        ConnectionStatusBadge: true,
        QuoteSelectionButton: true,
        QuoteChip: true,
      },
    },
  })
}

describe('WidgetConversations', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    api.listWidgetSessions.mockImplementation(async (_id: string, params: { mode: string }) => ({
      sessions: params.mode === 'waiting' ? [session(1, 'waiting')] : [session(2, 'human')],
    }))
    api.getWidgetSession.mockResolvedValue({ messages: [] })
  })

  it('lists waiting visitors first and taken-over chats on the second tab', async () => {
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.findAll('[data-testid="item-live-support-session"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('preview 1')
    expect(wrapper.emitted('waiting-count')?.at(-1)).toEqual([1])

    await wrapper.get('[data-testid="tab-live-support-mine"]').trigger('click')
    expect(wrapper.text()).toContain('preview 2')
    expect(wrapper.text()).not.toContain('preview 1')
  })

  it('explains an empty waiting list in one sentence', async () => {
    api.listWidgetSessions.mockResolvedValue({ sessions: [] })
    const wrapper = mountView()
    await flushPromises()

    expect(wrapper.get('[data-testid="live-support-empty"]').text()).toBe('No visitor is waiting.')
  })

  it('offers a retry instead of a raw error when loading fails', async () => {
    api.listWidgetSessions.mockRejectedValue(new Error('HTTP 500'))
    const wrapper = mountView()
    await flushPromises()

    const box = wrapper.get('[data-testid="live-support-load-error"]')
    expect(box.text()).toContain('Conversations could not be loaded.')
    expect(box.text()).not.toContain('500')
  })

  it('says the conversation was taken over when only the reply failed', async () => {
    api.takeOverSession.mockResolvedValue(undefined)
    api.sendHumanMessage.mockRejectedValue(new Error('down'))
    const wrapper = mountView()
    await flushPromises()

    await wrapper.get('[data-testid="item-live-support-session"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-testid="input-live-support-reply"]').setValue('Hello')
    await wrapper.get('[data-testid="btn-live-support-send"]').trigger('click')
    await flushPromises()

    expect(notify.error).toHaveBeenCalledWith(
      'You took over the conversation, but your reply was not sent.'
    )
  })
})
