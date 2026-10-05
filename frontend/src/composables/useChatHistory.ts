import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '../stores/auth'
import { useChatsStore, isDefaultChatTitle, type Chat as StoreChat } from '../stores/chats'
import { useSidebarStore } from '../stores/sidebar'
import { useIncomingStore } from '../stores/incoming'
import { useDialog } from './useDialog'
import { useDateFormat } from './useDateFormat'
import { isIamSharingEnabled } from './useIamFeature'
import { displaySessionTitle } from '@/utils/displaySessionTitle'
import { kindOfSharedItem, type ChatKind } from '@/utils/chatKind'

/**
 * One row of the history list: my own chat (`private`) or a conversation
 * someone shared with me (`incoming`). Incoming rows are read-only.
 */
export type HistoryChat = StoreChat & {
  kind: ChatKind
  kindLabel: string | null
  isNew: boolean
  incoming: boolean
}

/**
 * Pinned chats leave the collapsible list. Newest pin first.
 * Unpinned chats keep the order they were given.
 */
export function splitPinnedChats<T extends { pinned?: boolean; pinnedAt?: string | null }>(
  chats: readonly T[]
): { pinned: T[]; unpinned: T[] } {
  const pinned = chats
    .filter((chat) => chat.pinned === true)
    .sort((a, b) => (Date.parse(b.pinnedAt ?? '') || 0) - (Date.parse(a.pinnedAt ?? '') || 0))
  const unpinned = chats.filter((chat) => chat.pinned !== true)
  return { pinned, unpinned }
}

const isCreatingChat = ref(false)
const shareModalOpen = ref(false)
const shareModalChatId = ref<number | null>(null)
const shareModalChatTitle = ref('')
const iamShareOpen = ref(false)
const iamShareResourceId = ref('')

/** Share dialogs render once; both the panel and the phone sheet open them. */
export function useChatShareDialog() {
  return {
    shareModalOpen,
    shareModalChatId,
    shareModalChatTitle,
    iamShareOpen,
    iamShareResourceId,
  }
}

/**
 * Chat list shared by the desktop panel and the phone history sheet.
 * The create lock is module-scoped so a second click on either button is ignored.
 */
export function useChatHistory() {
  const { t } = useI18n()
  const route = useRoute()
  const router = useRouter()
  const dialog = useDialog()
  const { formatRelativeTime } = useDateFormat()
  const authStore = useAuthStore()
  const chatsStore = useChatsStore()
  const sidebarStore = useSidebarStore()
  const incomingStore = useIncomingStore()

  const iamSharingEnabled = computed(() => isIamSharingEnabled())
  const shareOwnerName = computed(
    () => authStore.user?.firstName?.trim() || authStore.user?.email || ''
  )

  const chatActivityTimestamp = (chat: StoreChat): number =>
    Date.parse(chat.updatedAt ?? '') || Date.parse(chat.createdAt ?? '') || 0

  const ownChatList = computed<HistoryChat[]>(() => {
    return chatsStore.chats
      .filter((chat) => {
        if (chat.widgetSession) return false
        if (chat.id === chatsStore.activeChatId) return true
        const isEmpty =
          (!chat.messageCount || chat.messageCount === 0) &&
          !chat.firstMessagePreview &&
          isDefaultChatTitle(chat.title, t('chat.newChat'))
        return !isEmpty
      })
      .map((chat) => ({
        ...chat,
        kind: 'private' as const,
        kindLabel: null,
        isNew: false,
        incoming: false,
      }))
  })

  const incomingChatList = computed<HistoryChat[]>(() => {
    if (!iamSharingEnabled.value) return []
    const ownIds = new Set(chatsStore.chats.map((chat) => chat.id))
    return incomingStore.chats
      .filter((item) => !ownIds.has(Number(item.id)))
      .map((item) => {
        const { kind, label } = kindOfSharedItem(item)
        const sharedAt = new Date((item.sharedAt ?? 0) * 1000).toISOString()
        return {
          id: Number(item.id),
          title: item.name,
          createdAt: sharedAt,
          updatedAt: sharedAt,
          messageCount: Number(item.meta?.messageCount ?? 0) || undefined,
          source: 'web' as const,
          access: item.permission as StoreChat['access'],
          kind,
          kindLabel: label,
          isNew: item.isNew === true,
          incoming: true,
        }
      })
  })

  const sortedOwnChats = computed(() =>
    [...ownChatList.value].sort((a, b) => chatActivityTimestamp(b) - chatActivityTimestamp(a))
  )

  const splitOwn = computed(() => splitPinnedChats(sortedOwnChats.value))
  const pinnedChats = computed(() => splitOwn.value.pinned)
  const unpinnedChats = computed(() => splitOwn.value.unpinned)

  const sheetChats = computed<HistoryChat[]>(() => {
    return [...unpinnedChats.value, ...incomingChatList.value].sort(
      (a, b) => chatActivityTimestamp(b) - chatActivityTimestamp(a)
    )
  })

  const displayTitle = (chat: StoreChat): string => {
    const raw = !isDefaultChatTitle(chat.title, t('chat.newChat'))
      ? chat.title
      : chat.firstMessagePreview || t('chat.newChat')
    return displaySessionTitle(raw)
  }

  const formatTimestamp = (dateStr: string): string => formatRelativeTime(new Date(dateStr))

  const isGenerating = (chat: StoreChat): boolean => chatsStore.activeRunChatIds.has(chat.id)

  const channelIcon = (chat: StoreChat): string | null => {
    switch (chat.source) {
      case 'whatsapp':
        return 'mdi:whatsapp'
      case 'telegram':
        return 'mdi:telegram'
      case 'email':
        return 'mdi:email-outline'
      case 'widget':
        return 'mdi:widgets-outline'
      case 'api':
        return 'mdi:console'
      default:
        return null
    }
  }

  const channelIconClass = (chat: StoreChat): string => {
    switch (chat.source) {
      case 'whatsapp':
        return 'text-green-500'
      case 'telegram':
        return 'text-[var(--channel-telegram)]'
      case 'email':
        return 'text-blue-500'
      case 'widget':
        return 'text-purple-500'
      case 'api':
        return 'text-orange-500'
      default:
        return ''
    }
  }

  const createChat = async (closeSheet: boolean) => {
    if (isCreatingChat.value) return
    isCreatingChat.value = true
    const sheetWasOpen = sidebarStore.chatSheetOpen
    try {
      await chatsStore.findOrCreateEmptyChat()
      if (route.path !== '/') router.push('/')
      if (closeSheet || sheetWasOpen) sidebarStore.closeChatSheet()
    } finally {
      isCreatingChat.value = false
    }
  }

  const selectChat = (chatId: number) => {
    const chat = sheetChats.value.find((row) => row.id === chatId)
    if (chat?.isNew) void incomingStore.markChatOpened(chatId)
    chatsStore.setActiveChat(chatId)
    if (route.path !== '/') router.push('/')
    sidebarStore.closeChatSheet()
  }

  const renameChat = async (chatId: number) => {
    const chat = chatsStore.chats.find((row) => row.id === chatId)
    const newTitle = await dialog.prompt({
      title: t('chat.rename'),
      message: t('chat.enterNewName'),
      placeholder: t('chat.namePlaceholder'),
      defaultValue: chat?.title || '',
      confirmText: t('common.rename'),
      cancelText: t('common.cancel'),
    })
    if (newTitle && newTitle.trim()) {
      await chatsStore.updateChatTitle(chatId, newTitle.trim())
    }
  }

  const deleteChat = async (chatId: number) => {
    const confirmed = await dialog.confirm({
      title: t('chat.delete'),
      message: t('chat.deleteConfirm'),
      confirmText: t('common.delete'),
      cancelText: t('common.cancel'),
      danger: true,
    })
    if (confirmed) {
      await chatsStore.deleteChat(chatId)
    }
  }

  const toggleChatPin = (chatId: number) => {
    void chatsStore.toggleChatPin(chatId)
  }

  const shareChat = (chatId: number) => {
    const chat = chatsStore.chats.find((row) => row.id === chatId)
    shareModalChatId.value = chatId
    shareModalChatTitle.value = chat?.title || 'Chat'
    if (isIamSharingEnabled()) {
      iamShareResourceId.value = String(chatId)
      iamShareOpen.value = true
      return
    }
    shareModalOpen.value = true
  }

  const openPublicLinkFromIam = () => {
    iamShareOpen.value = false
    shareModalOpen.value = true
  }

  return {
    isCreatingChat,
    iamSharingEnabled,
    shareOwnerName,
    ownChatList: sortedOwnChats,
    pinnedChats,
    unpinnedChats,
    incomingChatList,
    sheetChats,
    displayTitle,
    formatTimestamp,
    isGenerating,
    channelIcon,
    channelIconClass,
    createChat,
    selectChat,
    renameChat,
    toggleChatPin,
    deleteChat,
    shareChat,
    openPublicLinkFromIam,
    chatsStore,
    incomingStore,
  }
}
