<template>
  <!--
    Persistent desktop chat-history panel. Docks to the LEFT of the content
    area (immediately right of the navigation rail) on the chat route, so past
    conversations are always visible without opening a modal. Hidden on phone
    chrome (v2-desktop-chrome); the mobile drawer handles history there.
  -->
  <aside
    class="chat-history-panel v2-desktop-chrome flex-col flex-shrink-0 relative"
    :style="{ width: `${width}px` }"
    data-testid="comp-chat-history-panel"
  >
    <!-- Header: title + collapse -->
    <div class="flex items-center justify-between gap-2 px-4 pt-4 pb-3 flex-shrink-0">
      <div class="min-w-0">
        <h2 class="text-sm font-bold txt-primary leading-tight truncate">
          {{ $t('chat.recent') }}
        </h2>
        <p class="text-[11px] txt-secondary mt-0.5">
          {{ chatList.length }}
          {{ chatList.length === 1 ? $t('chat.conversation') : $t('chat.conversations') }}
        </p>
      </div>
      <button
        class="w-8 h-8 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors txt-secondary flex-shrink-0"
        :title="$t('chat.hideHistory')"
        :aria-label="$t('chat.hideHistory')"
        data-testid="btn-chat-history-collapse"
        @click="sidebarStore.toggleChatHistoryCollapsed()"
      >
        <Icon icon="mdi:chevron-double-left" class="w-5 h-5" />
      </button>
    </div>

    <!-- New chat -->
    <div class="px-3 flex-shrink-0">
      <button
        class="w-full flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl btn-primary text-sm font-medium transition-all hover:shadow-lg hover:shadow-brand/20"
        :disabled="isCreatingChat"
        data-testid="btn-chat-history-new"
        @click="handleNewChat"
      >
        <Icon
          :icon="isCreatingChat ? 'mdi:loading' : 'mdi:plus'"
          :class="['w-4 h-4', isCreatingChat && 'animate-spin']"
        />
        <span>{{ $t('chat.newChat') }}</span>
      </button>
    </div>

    <!-- Search -->
    <div class="px-3 pt-3 flex-shrink-0">
      <div class="relative">
        <Icon
          icon="mdi:magnify"
          class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 txt-secondary pointer-events-none"
        />
        <input
          v-model="chatSearchQuery"
          type="text"
          class="w-full pl-9 pr-3 py-2 text-sm rounded-xl bg-black/[0.04] dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/[0.06] txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/30 focus:border-[var(--brand)]/40 transition-all"
          :placeholder="$t('chat.browser.searchPlaceholder')"
          data-testid="input-chat-history-search"
        />
      </div>
    </div>

    <!-- Chat list -->
    <div class="flex-1 overflow-y-auto scroll-thin px-2 py-3 mt-1" data-testid="list-chat-history">
      <!--
        FOLDER SEAM (planned): once chat folders exist (BCHATFOLDERS +
        BCHATS.BFOLDERID), this flat list becomes a tree — one collapsible
        <section> per folder plus an "Ungrouped" bucket, with drag-and-drop to
        move a chat between folders (mirror FilesView / FolderMoveMenu). The
        list is already driven by `filteredChatList`, so grouping by
        `chat.folderId` is an additive change here.
      -->

      <!-- Empty: search miss -->
      <div
        v-if="filteredChatList.length === 0 && chatSearchQuery"
        class="flex flex-col items-center justify-center py-10 gap-3 text-center"
      >
        <div
          class="w-12 h-12 rounded-2xl bg-black/[0.04] dark:bg-white/[0.04] flex items-center justify-center"
        >
          <Icon icon="mdi:chat-question-outline" class="w-6 h-6 txt-secondary" />
        </div>
        <p class="text-sm txt-secondary">{{ $t('common.noResults') }}</p>
      </div>

      <!-- Empty: no chats -->
      <div
        v-else-if="filteredChatList.length === 0"
        class="flex flex-col items-center justify-center py-10 gap-3 text-center"
      >
        <div
          class="w-14 h-14 rounded-2xl bg-[var(--brand)]/[0.06] flex items-center justify-center"
        >
          <ChatBubbleLeftRightIcon class="w-7 h-7 text-[var(--brand)]/60" />
        </div>
        <p class="text-sm font-medium txt-primary">{{ $t('chat.noChats') }}</p>
      </div>

      <!-- Rows -->
      <div v-else class="space-y-1" role="list">
        <div
          v-for="chat in filteredChatList"
          :key="chat.id"
          role="listitem"
          class="group/chat relative flex items-center gap-2.5 px-2.5 py-2.5 rounded-xl cursor-pointer transition-all duration-150 active:scale-[0.99]"
          data-testid="row-chat-history"
          :class="
            chat.id === chatsStore.activeChatId
              ? 'bg-[var(--brand)]/[0.08] ring-1 ring-[var(--brand)]/20'
              : 'hover:bg-black/[0.03] dark:hover:bg-white/[0.03]'
          "
          @click="handleChatSelect(chat.id)"
        >
          <!-- Channel indicator -->
          <div
            class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 transition-colors"
            :class="
              chat.id === chatsStore.activeChatId
                ? 'bg-[var(--brand)]/15'
                : 'bg-black/[0.04] dark:bg-white/[0.04] group-hover/chat:bg-black/[0.06] dark:group-hover/chat:bg-white/[0.06]'
            "
          >
            <Icon
              v-if="getChannelIcon(chat)"
              :icon="getChannelIcon(chat)!"
              class="w-4 h-4"
              :class="getChannelIconClass(chat)"
            />
            <ChatBubbleLeftRightIcon
              v-else
              class="w-4 h-4"
              :class="
                chat.id === chatsStore.activeChatId
                  ? 'text-[var(--brand)]'
                  : 'txt-secondary opacity-60'
              "
            />
          </div>

          <!-- Content -->
          <div class="flex-1 min-w-0">
            <p
              class="text-[13px] leading-snug truncate"
              :class="
                chat.id === chatsStore.activeChatId
                  ? 'font-semibold text-[var(--brand)]'
                  : 'font-medium txt-primary'
              "
            >
              {{ getDisplayTitle(chat) }}
            </p>
            <div class="flex items-center gap-2 mt-0.5">
              <span class="text-[11px] txt-secondary">{{ formatTimestamp(chat.createdAt) }}</span>
              <span
                v-if="chat.isShared"
                class="text-[11px] text-[var(--brand)] flex items-center gap-0.5"
              >
                <Icon icon="mdi:link-variant" class="w-3 h-3" />
              </span>
              <span
                v-if="isGenerating(chat)"
                class="text-[11px] text-[var(--brand)] flex items-center gap-1"
                :title="$t('chat.stillGenerating')"
                data-testid="indicator-chat-history-active-run"
              >
                <span class="w-1.5 h-1.5 rounded-full bg-[var(--brand)] animate-pulse" />
                <span class="sr-only">{{ $t('chat.stillGenerating') }}</span>
              </span>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex-shrink-0" @click.stop>
            <button
              class="w-8 h-8 rounded-lg flex items-center justify-center opacity-0 group-hover/chat:opacity-100 focus:opacity-100 hover:bg-black/5 dark:hover:bg-white/5 transition-all"
              :class="chatMenuOpenId === chat.id && '!opacity-100 bg-black/5 dark:bg-white/5'"
              data-testid="btn-chat-history-row-menu"
              @click="toggleChatMenu(chat.id, $event)"
            >
              <Icon icon="mdi:dots-horizontal" class="w-4.5 h-4.5 txt-secondary" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Footer: browse all -->
    <div
      v-if="chatList.length > 5"
      class="flex-shrink-0 px-3 py-2.5 border-t border-black/[0.04] dark:border-white/[0.04]"
    >
      <button
        class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-xs font-medium text-[var(--brand)] bg-[var(--brand)]/[0.06] hover:bg-[var(--brand)]/[0.12] transition-all duration-150 group/show"
        data-testid="btn-chat-history-show-all"
        @click="$router.push('/statistics#chats')"
      >
        <ChartBarIcon class="w-3.5 h-3.5 opacity-70" />
        {{ $t('chat.showAll') }}
        <Icon
          icon="mdi:arrow-right"
          class="w-3.5 h-3.5 transition-transform duration-150 group-hover/show:translate-x-0.5"
        />
      </button>
    </div>

    <!-- Resize handle (drag the right edge to widen/narrow) -->
    <button
      class="chat-history-resize-handle group"
      :aria-label="$t('chat.resizePanel')"
      data-testid="btn-chat-history-resize"
      @mousedown="startResize"
    >
      <span
        class="chat-history-resize-bar group-hover:bg-[var(--brand)] group-active:bg-[var(--brand)]"
      />
    </button>
  </aside>

  <!-- Chat context menu (teleported to escape overflow clipping) -->
  <Teleport to="#app">
    <Transition
      enter-active-class="transition ease-out duration-100"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
      leave-active-class="transition ease-in duration-75"
      leave-from-class="opacity-100 scale-100"
      leave-to-class="opacity-0 scale-95"
    >
      <div
        v-if="chatMenuOpenId !== null"
        class="fixed inset-0 z-[150]"
        @click="chatMenuOpenId = null"
      >
        <div class="fixed w-44 dropdown-panel origin-top-right" :style="chatMenuStyle" @click.stop>
          <button
            class="dropdown-item"
            data-testid="btn-chat-history-share"
            @click="handleChatShare(chatMenuOpenId!)"
          >
            <Icon icon="mdi:share-variant-outline" class="w-4 h-4" />
            {{ $t('common.share') }}
          </button>
          <button
            class="dropdown-item"
            data-testid="btn-chat-history-rename"
            @click="handleChatRename(chatMenuOpenId!)"
          >
            <Icon icon="mdi:pencil-outline" class="w-4 h-4" />
            {{ $t('common.rename') }}
          </button>
          <button
            class="dropdown-item dropdown-item--danger"
            data-testid="btn-chat-history-delete"
            @click="handleChatDelete(chatMenuOpenId!)"
          >
            <Icon icon="mdi:delete-outline" class="w-4 h-4" />
            {{ $t('common.delete') }}
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Share modal -->
  <ChatShareModal
    :is-open="shareModalOpen"
    :chat-id="shareModalChatId"
    :chat-title="shareModalChatTitle"
    @close="shareModalOpen = false"
    @shared="chatsStore.loadChats()"
    @unshared="chatsStore.loadChats()"
  />
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ChatBubbleLeftRightIcon, ChartBarIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useSidebarStore } from '../stores/sidebar'
import { useChatsStore, isDefaultChatTitle, type Chat as StoreChat } from '../stores/chats'
import { useDialog } from '../composables/useDialog'
import { useI18n } from 'vue-i18n'
import { useDateFormat } from '@/composables/useDateFormat'
import { triggerHapticImpact } from '../services/api/nativeHaptics'
import ChatShareModal from './ChatShareModal.vue'

const { t } = useI18n()
const { formatRelativeTime } = useDateFormat()
const route = useRoute()
const router = useRouter()
const sidebarStore = useSidebarStore()
const chatsStore = useChatsStore()
const dialog = useDialog()

const chatSearchQuery = ref('')
const isCreatingChat = ref(false)
const chatMenuOpenId = ref<number | null>(null)
const chatMenuStyle = ref<Record<string, string>>({})
const shareModalOpen = ref(false)
const shareModalChatId = ref<number | null>(null)
const shareModalChatTitle = ref('')

// --- Panel width (resizable, remembered per device) --------------------------
const DEFAULT_WIDTH = 300
const MIN_WIDTH = 240
const MAX_WIDTH = 440
const WIDTH_STORAGE_KEY = 'synaplan_chat_history_width'

const readStoredWidth = (): number => {
  const stored = parseInt(localStorage.getItem(WIDTH_STORAGE_KEY) || '', 10)
  if (!Number.isFinite(stored)) return DEFAULT_WIDTH
  return Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, stored))
}

const width = ref(readStoredWidth())
const resizeStartX = ref(0)
const resizeStartWidth = ref(0)

const onResizeMove = (event: MouseEvent) => {
  const delta = event.clientX - resizeStartX.value
  width.value = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, resizeStartWidth.value + delta))
}

const stopResize = () => {
  document.removeEventListener('mousemove', onResizeMove)
  document.removeEventListener('mouseup', stopResize)
  document.body.style.cursor = ''
  document.body.style.userSelect = ''
  localStorage.setItem(WIDTH_STORAGE_KEY, String(width.value))
}

const startResize = (event: MouseEvent) => {
  event.preventDefault()
  resizeStartX.value = event.clientX
  resizeStartWidth.value = width.value
  document.body.style.cursor = 'col-resize'
  document.body.style.userSelect = 'none'
  document.addEventListener('mousemove', onResizeMove)
  document.addEventListener('mouseup', stopResize)
}

// --- Chat list (mirrors the SidebarV2 history sheet) -------------------------
const chatActivityTimestamp = (chat: StoreChat): number =>
  Date.parse(chat.updatedAt ?? '') || Date.parse(chat.createdAt ?? '') || 0

const chatList = computed(() =>
  chatsStore.chats
    .filter((c) => {
      if (c.widgetSession) return false
      if (c.id === chatsStore.activeChatId) return true
      const isEmpty =
        (!c.messageCount || c.messageCount === 0) &&
        !c.firstMessagePreview &&
        isDefaultChatTitle(c.title, t('chat.newChat'))
      return !isEmpty
    })
    .slice()
    .sort((a, b) => chatActivityTimestamp(b) - chatActivityTimestamp(a))
)

const filteredChatList = computed(() => {
  const q = chatSearchQuery.value.toLowerCase().trim()
  if (!q) return chatList.value
  return chatList.value.filter((c) => getDisplayTitle(c).toLowerCase().includes(q))
})

const getDisplayTitle = (chat: StoreChat): string => {
  if (!isDefaultChatTitle(chat.title, t('chat.newChat'))) return chat.title
  if (chat.firstMessagePreview) return chat.firstMessagePreview
  return t('chat.newChat')
}

const formatTimestamp = (dateStr: string): string => formatRelativeTime(new Date(dateStr))

const isGenerating = (chat: StoreChat): boolean => chatsStore.activeRunChatIds.has(chat.id)

const getChannelIcon = (chat: StoreChat): string | null => {
  switch (chat.source) {
    case 'whatsapp':
      return 'mdi:whatsapp'
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

const getChannelIconClass = (chat: StoreChat): string => {
  switch (chat.source) {
    case 'whatsapp':
      return 'text-green-500'
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

// --- Actions -----------------------------------------------------------------
const handleNewChat = async () => {
  if (isCreatingChat.value) return
  isCreatingChat.value = true
  try {
    await chatsStore.findOrCreateEmptyChat()
    if (route.path !== '/') router.push('/')
  } finally {
    setTimeout(() => {
      isCreatingChat.value = false
    }, 300)
  }
}

const handleChatSelect = (chatId: number) => {
  chatsStore.setActiveChat(chatId)
  if (route.path !== '/') router.push('/')
  chatMenuOpenId.value = null
}

const handleChatRename = async (chatId: number) => {
  const chat = chatsStore.chats.find((c) => c.id === chatId)
  chatMenuOpenId.value = null
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

const handleChatDelete = async (chatId: number) => {
  chatMenuOpenId.value = null
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

const handleChatShare = (chatId: number) => {
  const chat = chatsStore.chats.find((c) => c.id === chatId)
  shareModalChatId.value = chatId
  shareModalChatTitle.value = chat?.title || 'Chat'
  shareModalOpen.value = true
  chatMenuOpenId.value = null
}

const toggleChatMenu = (chatId: number, event: MouseEvent) => {
  triggerHapticImpact('light')
  if (chatMenuOpenId.value === chatId) {
    chatMenuOpenId.value = null
    return
  }
  const btn = event.currentTarget as HTMLElement
  const rect = btn.getBoundingClientRect()
  const menuHeight = 140
  const menuWidth = 176
  const spaceBelow = window.innerHeight - rect.bottom
  const top = spaceBelow < menuHeight ? rect.top - menuHeight : rect.bottom + 4
  const left = Math.max(8, rect.right - menuWidth)
  chatMenuStyle.value = { top: `${top}px`, left: `${left}px` }
  chatMenuOpenId.value = chatId
}

const handleEscape = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && chatMenuOpenId.value !== null) {
    chatMenuOpenId.value = null
  }
}

onMounted(() => {
  chatsStore.loadChats()
  document.addEventListener('keydown', handleEscape)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleEscape)
  document.removeEventListener('mousemove', onResizeMove)
  document.removeEventListener('mouseup', stopResize)
})
</script>

<style scoped>
.chat-history-panel {
  /* Distinct from the main content so the column reads as a sidebar in both
     themes; the token flips automatically in dark / V2. */
  background: var(--bg-sidebar);
  border-right: 1px solid var(--border-light);
}

.chat-history-resize-handle {
  position: absolute;
  top: 0;
  right: -6px;
  width: 12px;
  height: 100%;
  padding: 0;
  border: 0;
  background: transparent;
  cursor: col-resize;
  display: flex;
  align-items: stretch;
  justify-content: center;
  z-index: 5;
}

.chat-history-resize-bar {
  width: 2px;
  height: 100%;
  background: transparent;
  transition: background-color 0.15s ease;
}
</style>
