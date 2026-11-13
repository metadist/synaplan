import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ChatMessage from '@/components/ChatMessage.vue'
import type { MessageFile, Part } from '@/stores/history'

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), resolve: () => ({ href: '#' }) }),
}))

const pdfFile: MessageFile = {
  id: 11,
  filename: 'report.pdf',
  fileType: 'pdf',
  filePath: '/uploads/report.pdf',
  fileSize: 1024,
}

const imageFile: MessageFile = {
  id: 12,
  filename: 'photo.png',
  fileType: 'png',
  filePath: '/uploads/photo.png',
}

const audioFile: MessageFile = {
  id: 13,
  filename: 'voice.ogg',
  fileType: 'ogg',
  filePath: '/uploads/voice.ogg',
  fileMime: 'audio/ogg',
}

function mountChatMessage(props: {
  role?: 'user' | 'assistant'
  parts?: Part[]
  files?: MessageFile[]
  isGuestMode?: boolean
  canRewrite?: boolean
  quotedText?: string | null
  tool?: { command?: string; icon: string; label: string } | null
  status?: 'sent' | 'failed' | 'rate_limited'
}) {
  return mount(ChatMessage, {
    props: {
      role: props.role ?? 'user',
      parts: props.parts ?? [],
      timestamp: new Date('2026-11-13T10:00:00Z'),
      files: props.files,
      isGuestMode: props.isGuestMode,
      canRewrite: props.canRewrite,
      quotedText: props.quotedText,
      tool: props.tool,
      status: props.status,
    },
    global: {
      mocks: {
        $t: (key: string) => key,
      },
      stubs: {
        RouterLink: true,
        Icon: true,
        FileOfficeActions: true,
        MessagePart: true,
        MessageMemories: true,
        MessageFeedbacks: true,
        ServiceIcon: true,
        ModelCostBadge: true,
        ToolBadge: true,
        TaskPlanBubble: true,
        MediaJobStatus: true,
        ExternalLinkWarning: true,
      },
    },
  })
}

describe('ChatMessage sent files', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('offers document reattach for regular writable chats and hides it for guests and read-only chats', () => {
    const writableUser = mountChatMessage({ files: [pdfFile], canRewrite: true })
    expect(writableUser.find('[data-testid="btn-message-file-reattach"]').exists()).toBe(true)

    const guestUser = mountChatMessage({ files: [pdfFile], isGuestMode: true })
    expect(guestUser.find('[data-testid="btn-message-file-reattach"]').exists()).toBe(false)

    const readOnlyUser = mountChatMessage({ files: [pdfFile], canRewrite: false })
    expect(readOnlyUser.find('[data-testid="btn-message-file-reattach"]').exists()).toBe(false)

    const readOnlyAssistant = mountChatMessage({
      role: 'assistant',
      parts: [{ type: 'text', content: 'Attached report.' }],
      files: [pdfFile],
      canRewrite: false,
    })
    expect(readOnlyAssistant.find('[data-testid="btn-message-file-reattach"]').exists()).toBe(false)
  })

  it('omits the empty user bubble on file-only turns and keeps it for captions, quotes, audio players, and rate limits', () => {
    const fileOnly = mountChatMessage({ files: [imageFile, pdfFile], parts: [] })
    expect(fileOnly.find('[data-testid="section-user-attachments"]').exists()).toBe(true)
    expect(fileOnly.find('[data-testid="user-message-bubble"]').exists()).toBe(false)

    const blankTextWithFile = mountChatMessage({
      files: [pdfFile],
      parts: [{ type: 'text', content: '   ' }],
    })
    expect(blankTextWithFile.find('[data-testid="user-message-bubble"]').exists()).toBe(false)

    const captioned = mountChatMessage({
      files: [imageFile],
      parts: [{ type: 'text', content: 'What is in this photo?' }],
    })
    expect(captioned.find('[data-testid="section-user-attachments"]').exists()).toBe(true)
    expect(captioned.find('[data-testid="user-message-bubble"]').exists()).toBe(true)

    const reloadedAudioOnly = mountChatMessage({
      files: [audioFile],
      parts: [{ type: 'audio', url: '/uploads/voice.ogg' }],
    })
    expect(reloadedAudioOnly.find('[data-testid="user-message-bubble"]').exists()).toBe(true)

    const quotedFileOnly = mountChatMessage({
      files: [pdfFile],
      parts: [],
      quotedText: 'Earlier context',
    })
    expect(quotedFileOnly.find('[data-testid="user-message-bubble"]').exists()).toBe(true)

    const rateLimitedFileOnly = mountChatMessage({
      files: [pdfFile],
      parts: [],
      status: 'rate_limited',
    })
    expect(rateLimitedFileOnly.find('[data-testid="user-message-bubble"]').exists()).toBe(true)
  })
})
