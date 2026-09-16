import { describe, it, expect, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const appBaseUrl = vi.hoisted(() => ({ value: 'https://web.synaplan.com' }))
const getShareInfo = vi.hoisted(() => vi.fn())

vi.mock('@/stores/config', () => ({
  useConfigStore: () => ({
    get appBaseUrl() {
      return appBaseUrl.value
    },
  }),
}))

vi.mock('@/stores/chats', () => ({
  useChatsStore: () => ({
    getShareInfo,
    shareChat: vi.fn(),
  }),
}))

vi.mock('@/composables/useNotification', () => ({
  useNotification: () => ({
    success: vi.fn(),
    error: vi.fn(),
  }),
}))

vi.mock('@/composables/useEscapeKey', () => ({
  useEscapeKey: vi.fn(),
}))

import ChatShareModal from '@/components/ChatShareModal.vue'

async function mountOpenModal() {
  const wrapper = mount(ChatShareModal, {
    props: {
      isOpen: false,
      chatId: 42,
      chatTitle: 'Weekly recap',
    },
    global: {
      mocks: {
        $t: (key: string) => key,
      },
      stubs: {
        Teleport: true,
        Transition: false,
      },
    },
  })
  // Share info loads when the modal opens, same as the in-app usage.
  await wrapper.setProps({ isOpen: true })
  await flushPromises()
  return wrapper
}

describe('ChatShareModal share URL', () => {
  beforeEach(() => {
    appBaseUrl.value = 'https://web.synaplan.com'
    getShareInfo.mockReset()
    getShareInfo.mockResolvedValue({
      isShared: true,
      shareToken: 'share_tok_1',
      shareUrl: 'https://web.synaplan.com/shared/share_tok_1',
    })
  })

  it('shows a platform HTTPS link, not the current page origin', async () => {
    const wrapper = await mountOpenModal()

    const link = wrapper.find('[data-testid="share-link-input"]')
    expect(link.exists()).toBe(true)
    expect(link.text()).toBe('https://web.synaplan.com/shared/share_tok_1')
    expect(link.text()).not.toContain(window.location.origin)
    expect(link.text()).not.toContain('capacitor://')

    wrapper.unmount()
  })

  it('follows a staging platform origin from appBaseUrl', async () => {
    appBaseUrl.value = 'https://staging.synaplan.com'
    const wrapper = await mountOpenModal()

    expect(wrapper.find('[data-testid="share-link-input"]').text()).toBe(
      'https://staging.synaplan.com/shared/share_tok_1'
    )
    expect(wrapper.find('[data-testid="share-link-input"]').text()).not.toContain(
      window.location.origin
    )

    wrapper.unmount()
  })

  it('loads the platform link when the modal is already open on mount', async () => {
    const wrapper = mount(ChatShareModal, {
      props: {
        isOpen: true,
        chatId: 42,
        chatTitle: 'Weekly recap',
      },
      global: {
        mocks: {
          $t: (key: string) => key,
        },
        stubs: {
          Teleport: true,
          Transition: false,
        },
      },
    })
    await flushPromises()

    expect(wrapper.find('[data-testid="share-link-input"]').text()).toBe(
      'https://web.synaplan.com/shared/share_tok_1'
    )

    wrapper.unmount()
  })
})
