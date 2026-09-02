<template>
  <!--
    Chat history embedded INSIDE the expanded navigation rail (not a second
    column). Deliberately compact: one line per chat, no avatar tile, no card
    padding — the sidebar must stay narrow on every screen size.
  -->
  <div class="flex flex-col min-h-0" data-testid="comp-sidebar-chat-history">
    <div class="flex items-center justify-between gap-2 px-2 pb-1 flex-shrink-0">
      <p class="text-[11px] font-semibold uppercase tracking-wider txt-secondary opacity-70">
        {{ $t('nav.history') }}
      </p>
      <button
        class="w-6 h-6 rounded-md flex items-center justify-center txt-secondary hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
        :class="searchOpen && 'bg-black/5 dark:bg-white/5'"
        :title="$t('chat.browser.searchPlaceholder')"
        :aria-label="$t('chat.browser.searchPlaceholder')"
        :aria-expanded="searchOpen"
        data-testid="btn-sidebar-history-search-toggle"
        @click="toggleSearch"
      >
        <Icon icon="mdi:magnify" class="w-4 h-4" />
      </button>
    </div>

    <!-- Search is opt-in so the resting state stays minimal -->
    <div v-if="searchOpen" class="px-2 pb-1.5 flex-shrink-0">
      <input
        ref="searchInput"
        v-model="query"
        type="text"
        class="w-full px-2.5 py-1.5 text-[13px] rounded-lg bg-black/[0.04] dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/[0.06] txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/30 transition-all"
        :placeholder="$t('chat.browser.searchPlaceholder')"
        data-testid="input-sidebar-history-search"
        @keydown.escape="closeSearch"
      />
    </div>

    <div class="flex-1 min-h-0 overflow-y-auto scroll-thin px-1" data-testid="list-sidebar-history">
      <p v-if="chats.length === 0" class="px-2 py-3 text-[12px] txt-secondary">
        {{ query ? $t('common.noResults') : $t('chat.noChats') }}
      </p>

      <!--
        FOLDER SEAM (planned): once chat folders / projects exist
        (BCHATPROJECTS + BCHATS.BPROJECTID), this flat list becomes one
        collapsible group per project plus an unfiled bucket. Grouping by
        `chat.folderId` is additive here.
      -->
      <div
        v-for="chat in chats"
        :key="chat.id"
        role="button"
        tabindex="0"
        class="group/chat relative flex items-center gap-1.5 pl-2 pr-1 py-[7px] rounded-lg cursor-pointer transition-colors"
        :class="
          chat.id === chatsStore.activeChatId
            ? 'bg-[var(--brand)]/[0.10] txt-primary'
            : 'hover:bg-black/[0.04] dark:hover:bg-white/[0.05]'
        "
        data-testid="row-sidebar-history"
        @click="select(chat.id)"
        @keydown.enter="select(chat.id)"
        @keydown.space.prevent="select(chat.id)"
      >
        <Icon
          v-if="channelIcon(chat)"
          :icon="channelIcon(chat)!"
          class="w-3.5 h-3.5 flex-shrink-0"
          :class="channelIconClass(chat)"
          aria-hidden="true"
        />
        <span
          class="flex-1 min-w-0 truncate text-[13px] leading-tight"
          :class="chat.id === chatsStore.activeChatId ? 'font-semibold' : 'txt-primary'"
          :title="displayTitle(chat)"
        >
          {{ displayTitle(chat) }}
        </span>

        <span
          v-if="isGenerating(chat)"
          class="w-1.5 h-1.5 rounded-full bg-[var(--brand)] animate-pulse flex-shrink-0"
          :title="$t('chat.stillGenerating')"
          data-testid="indicator-sidebar-history-run"
        />

        <button
          class="w-6 h-6 rounded-md flex items-center justify-center flex-shrink-0 opacity-0 group-hover/chat:opacity-100 focus:opacity-100 hover:bg-black/10 dark:hover:bg-white/10 transition-opacity"
          :class="menuOpenId === chat.id && '!opacity-100'"
          :aria-label="$t('common.actions')"
          data-testid="btn-sidebar-history-row-menu"
          @click.stop="openMenu(chat.id, $event)"
        >
          <Icon icon="mdi:dots-horizontal" class="w-4 h-4 txt-secondary" />
        </button>
      </div>
    </div>
  </div>

  <!-- Row actions (teleported so the narrow sidebar never clips it) -->
  <Teleport to="#app">
    <div v-if="menuOpenId !== null" class="fixed inset-0 z-[150]" @click="menuOpenId = null">
      <div class="fixed w-44 dropdown-panel origin-top-left" :style="menuStyle" @click.stop>
        <button
          class="dropdown-item"
          data-testid="btn-sidebar-history-share"
          @click="share(menuOpenId!)"
        >
          <Icon icon="mdi:share-variant-outline" class="w-4 h-4" />
          {{ $t('common.share') }}
        </button>
        <button
          class="dropdown-item"
          data-testid="btn-sidebar-history-rename"
          @click="rename(menuOpenId!)"
        >
          <Icon icon="mdi:pencil-outline" class="w-4 h-4" />
          {{ $t('common.rename') }}
        </button>
        <button
          class="dropdown-item dropdown-item--danger"
          data-testid="btn-sidebar-history-delete"
          @click="remove(menuOpenId!)"
        >
          <Icon icon="mdi:delete-outline" class="w-4 h-4" />
          {{ $t('common.delete') }}
        </button>
      </div>
    </div>
  </Teleport>

  <ChatShareModal
    :is-open="shareOpen"
    :chat-id="shareChatId"
    :chat-title="shareChatTitle"
    @close="shareOpen = false"
    @shared="chatsStore.loadChats()"
    @unshared="chatsStore.loadChats()"
  />
</template>

<script setup lang="ts">
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { useChatsStore, isDefaultChatTitle, type Chat as StoreChat } from '../stores/chats'
import { useDialog } from '../composables/useDialog'
import ChatShareModal from './ChatShareModal.vue'

/** Lets a host that renders this as an overlay (the collapsed-rail flyout) close itself. */
const emit = defineEmits<{ navigate: [] }>()

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const chatsStore = useChatsStore()
const dialog = useDialog()

const query = ref('')
const searchOpen = ref(false)
const searchInput = ref<HTMLInputElement | null>(null)
const menuOpenId = ref<number | null>(null)
const menuStyle = ref<Record<string, string>>({})
const shareOpen = ref(false)
const shareChatId = ref<number | null>(null)
const shareChatTitle = ref('')

const toggleSearch = async () => {
  searchOpen.value = !searchOpen.value
  if (searchOpen.value) {
    await nextTick()
    searchInput.value?.focus()
  } else {
    query.value = ''
  }
}

const closeSearch = () => {
  searchOpen.value = false
  query.value = ''
}

const activity = (chat: StoreChat): number =>
  Date.parse(chat.updatedAt ?? '') || Date.parse(chat.createdAt ?? '') || 0

const chats = computed(() => {
  const term = query.value.toLowerCase().trim()
  return chatsStore.chats
    .filter((c) => {
      // Widget sessions belong to their own view, never the personal history.
      if (c.widgetSession) return false
      if (c.id !== chatsStore.activeChatId) {
        const empty =
          (!c.messageCount || c.messageCount === 0) &&
          !c.firstMessagePreview &&
          isDefaultChatTitle(c.title, t('chat.newChat'))
        if (empty) return false
      }
      return !term || displayTitle(c).toLowerCase().includes(term)
    })
    .slice()
    .sort((a, b) => activity(b) - activity(a))
})

function displayTitle(chat: StoreChat): string {
  if (!isDefaultChatTitle(chat.title, t('chat.newChat'))) return chat.title
  return chat.firstMessagePreview || t('chat.newChat')
}

const isGenerating = (chat: StoreChat): boolean => chatsStore.activeRunChatIds.has(chat.id)

const channelIcon = (chat: StoreChat): string | null => {
  switch (chat.source) {
    case 'whatsapp':
      return 'mdi:whatsapp'
    case 'email':
      return 'mdi:email-outline'
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
    case 'email':
      return 'text-blue-500'
    default:
      return 'txt-secondary'
  }
}

const select = (chatId: number) => {
  chatsStore.setActiveChat(chatId)
  if (route.path !== '/') router.push('/')
  menuOpenId.value = null
  emit('navigate')
}

const openMenu = (chatId: number, event: MouseEvent) => {
  if (menuOpenId.value === chatId) {
    menuOpenId.value = null
    return
  }
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const menuHeight = 140
  const spaceBelow = window.innerHeight - rect.bottom
  menuStyle.value = {
    left: `${rect.right + 6}px`,
    top: `${spaceBelow < menuHeight ? Math.max(8, rect.top - menuHeight) : rect.bottom + 4}px`,
  }
  menuOpenId.value = chatId
}

const rename = async (chatId: number) => {
  const chat = chatsStore.chats.find((c) => c.id === chatId)
  menuOpenId.value = null
  const title = await dialog.prompt({
    title: t('chat.rename'),
    message: t('chat.enterNewName'),
    placeholder: t('chat.namePlaceholder'),
    defaultValue: chat?.title || '',
    confirmText: t('common.rename'),
    cancelText: t('common.cancel'),
  })
  if (title && title.trim()) {
    await chatsStore.updateChatTitle(chatId, title.trim())
  }
}

const remove = async (chatId: number) => {
  menuOpenId.value = null
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

const share = (chatId: number) => {
  const chat = chatsStore.chats.find((c) => c.id === chatId)
  shareChatId.value = chatId
  shareChatTitle.value = chat?.title || 'Chat'
  shareOpen.value = true
  menuOpenId.value = null
}

const onEscape = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && menuOpenId.value !== null) menuOpenId.value = null
}

onMounted(() => {
  chatsStore.loadChats()
  document.addEventListener('keydown', onEscape)
})

onBeforeUnmount(() => document.removeEventListener('keydown', onEscape))
</script>
