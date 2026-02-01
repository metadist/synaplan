<template>
  <div class="relative" data-testid="comp-chat-dropdown">
    <!-- Chat Button -->
    <button
      :class="[
        'w-full group flex items-center gap-3 rounded-xl px-3 min-h-[42px] nav-item',
        isCollapsed ? 'justify-center py-2' : 'py-2.5 justify-between',
        (isActive || isOpen) && 'nav-item--active',
      ]"
      data-testid="btn-chat-toggle"
      @click="handleMainChatClick"
    >
      <div class="flex items-center gap-3">
        <ChatBubbleLeftRightIcon class="w-5 h-5 flex-shrink-0" />
        <span v-if="!isCollapsed" class="font-medium text-sm truncate">{{ $t('nav.chat') }}</span>
      </div>
      <ChevronDownIcon
        v-if="!isCollapsed"
        :class="['w-4 h-4 transition-transform', isOpen && 'rotate-180']"
      />
    </button>

    <!-- Dropdown Content -->
    <div
      v-if="isOpen && !isCollapsed"
      class="mt-1 ml-1 pl-2 border-l border-dotted border-gray-400/40 dark:border-gray-500/30 pt-2"
      data-testid="section-chat-dropdown"
    >
      <!-- New Chat Button - Prominent at top with glow effect -->
      <button
        class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg btn-primary text-sm font-medium mb-2 min-h-[36px] transition-all duration-200 hover:shadow-lg hover:shadow-brand/30 hover:scale-[1.02]"
        data-testid="btn-chat-new-dropdown"
        @click="handleNewChat"
      >
        <PlusIcon class="w-4 h-4" />
        <span>{{ $t('chat.newChat') }}</span>
      </button>

      <!-- Divider with label -->
      <div class="relative mt-2 mb-0.5">
        <div class="absolute inset-0 flex items-center">
          <div class="w-full border-t border-dotted border-gray-400/50 dark:border-gray-500/40" />
        </div>
        <div class="relative flex justify-center">
          <span class="px-2 text-xs font-medium txt-secondary bg-sidebar flex items-center gap-1.5">
            <ClockIcon class="w-3 h-3" />
            {{ $t('chat.recent') }}
          </span>
        </div>
      </div>

      <!-- Chats List -->
      <div v-if="displayedChats.length > 0" class="flex flex-col gap-1">
        <div
          v-for="chat in displayedChats"
          :key="chat.id"
          class="group/item relative flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-colors min-h-[36px]"
          :class="chat.id === activeChat ? 'bg-brand/10 txt-brand' : 'txt-secondary hover-surface'"
        >
          <button
            class="flex-1 text-left flex flex-col min-w-0 py-0.5"
            :data-testid="`btn-chat-item-${chat.id}`"
            @click="handleChatItemClick(chat.id)"
          >
            <span class="truncate text-sm max-w-[150px]">{{ getDisplayTitle(chat) }}</span>
            <span class="text-[10px] txt-secondary opacity-70">{{ formatTimestamp(chat.createdAt) }}</span>
          </button>

          <!-- Actions Menu -->
          <div class="relative">
            <button
              class="opacity-0 group-hover/item:opacity-100 p-1 rounded hover:bg-black/5 dark:hover:bg-white/5 transition-all"
              :data-testid="`btn-chat-menu-${chat.id}`"
              @click.stop="toggleChatMenu(chat.id)"
            >
              <EllipsisVerticalIcon class="w-4 h-4" />
            </button>

            <!-- Dropdown Menu -->
            <div
              v-if="openMenuChatId === chat.id"
              class="absolute right-0 mt-1 w-36 dropdown-panel z-30"
            >
              <button
                class="dropdown-item"
                :data-testid="`btn-chat-share-${chat.id}`"
                @click.stop="handleShare(chat.id)"
              >
                {{ $t('common.share') }}
              </button>
              <button
                class="dropdown-item"
                :data-testid="`btn-chat-rename-${chat.id}`"
                @click.stop="handleRename(chat.id)"
              >
                {{ $t('common.rename') }}
              </button>
              <button
                class="dropdown-item dropdown-item--danger"
                :data-testid="`btn-chat-delete-${chat.id}`"
                @click.stop="handleDelete(chat.id)"
              >
                {{ $t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- No chats message -->
      <div v-else class="px-3 py-2 text-sm txt-secondary italic">
        {{ $t('chat.noChats') }}
      </div>

      <!-- Show All Button -->
      <button
        v-if="hasMoreChats"
        class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg txt-secondary hover-surface transition-colors text-sm font-medium mt-1 min-h-[36px]"
        data-testid="btn-chat-show-all"
        @click="navigateToStatistics"
      >
        <span>{{ $t('chat.showAll') }}</span>
      </button>
    </div>

    <!-- Chat Share Modal -->
    <ChatShareModal
      :is-open="shareModalOpen"
      :chat-id="shareModalChatId"
      :chat-title="shareModalChatTitle"
      @close="shareModalOpen = false"
      @shared="chatsStore.loadChats()"
      @unshared="chatsStore.loadChats()"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  ChatBubbleLeftRightIcon,
  ChatBubbleLeftIcon,
  ChevronDownIcon,
  EllipsisVerticalIcon,
  PlusIcon,
  ClockIcon,
} from '@heroicons/vue/24/outline'
import { useChatsStore } from '@/stores/chats'
import { useDialog } from '@/composables/useDialog'
import { useRouter } from 'vue-router'
import ChatShareModal from './ChatShareModal.vue'

interface Props {
  isCollapsed?: boolean
}

defineProps<Props>()

const route = useRoute()
const router = useRouter()
const chatsStore = useChatsStore()
const dialog = useDialog()
const { t } = useI18n()

const isOpen = ref(false)
const openMenuChatId = ref<number | null>(null)
const shareModalOpen = ref(false)
const shareModalChatId = ref<number | null>(null)
const shareModalChatTitle = ref<string>('')

const MAX_RECENT_CHATS = 4

const toggleDropdown = () => {
  isOpen.value = !isOpen.value
  if (!isOpen.value) {
    openMenuChatId.value = null
  }
}

const handleMainChatClick = async () => {
  // Check if we have an active chat with messages
  const currentChat = chatsStore.chats.find((c) => c.id === chatsStore.activeChatId)
  const hasMessages = currentChat && (currentChat.messageCount ?? 0) > 0

  // Only create new chat if:
  // 1. Dropdown is currently closed AND
  // 2. Either no active chat OR active chat is empty
  if (!isOpen.value && !hasMessages) {
    await handleNewChat()
  }

  // Always toggle dropdown
  toggleDropdown()
}

const toggleChatMenu = (chatId: number) => {
  openMenuChatId.value = openMenuChatId.value === chatId ? null : chatId
}

const navigateToStatistics = () => {
  router.push('/statistics#chats')
  isOpen.value = false
}

// Active if we're on chat route
const isActive = computed(() => route.path === '/')

// Active chat ID
const activeChat = computed(() => chatsStore.activeChatId)

// Format a date as relative or compact timestamp
const formatTimestamp = (dateStr: string): string => {
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)

  if (diffMins < 1) return 'just now'
  if (diffMins < 60) return `${diffMins}m ago`
  if (diffHours < 24) return `${diffHours}h ago`
  if (diffDays < 7) return `${diffDays}d ago`
  
  // Fallback to compact date
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${month}/${day}`
}

// Get the display title - prefer first message preview, then title
const getDisplayTitle = (chat: { title: string; firstMessagePreview?: string | null; messageCount?: number }): string => {
  // If we have a first message preview, use it
  if (chat.firstMessagePreview) {
    return chat.firstMessagePreview
  }
  
  // If title is not default, use the title
  const isDefaultTitle = chat.title === 'New Chat' || chat.title === 'Neuer Chat' || chat.title.startsWith('Chat ')
  if (!isDefaultTitle) {
    return chat.title
  }
  
  // Fallback for empty chats
  return 'Empty chat'
}

// Get all chats (excluding widget sessions and truly empty chats)
const allChats = computed(() => {
  return chatsStore.chats.filter((c) => {
    // Exclude widget sessions
    if (c.widgetSession) return false
    
    // Always show the active chat (even if empty, so user sees current context)
    if (c.id === chatsStore.activeChatId) return true
    
    // Filter out truly empty chats (no messages and no content)
    const isEmpty = (!c.messageCount || c.messageCount === 0) && !c.firstMessagePreview
    if (isEmpty) return false
    
    return true
  })
})

// Display chats based on showAll state
const displayedChats = computed(() => {
  return allChats.value.slice(0, MAX_RECENT_CHATS)
})

const hasMoreChats = computed(() => {
  return allChats.value.length > MAX_RECENT_CHATS
})

const handleChatItemClick = (chatId: number) => {
  chatsStore.activeChatId = chatId
  if (route.path !== '/') {
    router.push('/')
  }
  // Don't close dropdown - keep it open to show highlighted state
  openMenuChatId.value = null
}

const handleShare = (chatId: number) => {
  const chat = chatsStore.chats.find((c) => c.id === chatId)
  shareModalChatId.value = chatId
  shareModalChatTitle.value = chat?.title || 'Chat'
  shareModalOpen.value = true
  openMenuChatId.value = null
}

const handleRename = async (chatId: number) => {
  const chat = chatsStore.chats.find((c) => c.id === chatId)
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
  openMenuChatId.value = null
}

const handleDelete = async (chatId: number) => {
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
  openMenuChatId.value = null
}

const handleNewChat = async () => {
  await chatsStore.findOrCreateEmptyChat()
  if (route.path !== '/') {
    router.push('/')
  }
  // Don't close dropdown - keep it open
  openMenuChatId.value = null
}

// Close menu when clicking outside
const handleClickOutside = (event: MouseEvent) => {
  const target = event.target as HTMLElement
  // Check if click is outside the dropdown
  if (!target.closest('[data-testid="comp-chat-dropdown"]')) {
    openMenuChatId.value = null
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>
