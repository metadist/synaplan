<template>
  <div class="flex flex-col gap-2">
    <!-- New Chat Button -->
    <button
      @click="createNewChat"
      class="w-full flex items-center justify-center gap-2 px-3 py-2.5 bg-gradient-to-r from-brand-from to-brand-to hover:opacity-90 text-white rounded-lg transition-opacity focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
    >
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
      </svg>
      <span class="font-medium text-sm">New Chat</span>
    </button>

    <div>
      <button
        @click="toggleSection('my')"
        class="w-full flex items-center gap-2 px-3 py-2 rounded-lg txt-secondary hover-surface transition-colors text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary min-h-[44px]"
      >
        <ChevronRightIcon :class="['w-4 h-4 transition-transform flex-shrink-0', sections.my && 'rotate-90']" />
        <span class="text-xs font-medium uppercase tracking-wider">My Chats</span>
      </button>

      <div v-if="sections.my" class="flex flex-col gap-1 mt-1">
        <SidebarChatListItem
          v-for="chat in myChats"
          :key="chat.id"
          :chat="chat"
          :is-active="chat.id === activeChat"
          @open="openChat"
          @share="handleShare"
          @rename="handleRename"
          @delete="handleDelete"
        />

        <button
          v-if="!showAllMy && myChats.length > 5"
          @click="showAllMy = true"
          class="px-3 py-2 rounded-lg txt-secondary hover-surface transition-colors text-left text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary min-h-[44px]"
        >
          Show more...
        </button>

        <button
          v-if="myArchivedChats.length > 0"
          @click="toggleSection('myArchived')"
          class="flex items-center gap-2 px-3 py-2 rounded-lg txt-secondary hover-surface transition-colors text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] mt-2"
        >
          <ChevronRightIcon :class="['w-3.5 h-3.5 transition-transform flex-shrink-0', sections.myArchived && 'rotate-90']" />
          <span class="text-xs font-medium uppercase tracking-wider">Archived ({{ myArchivedChats.length }})</span>
        </button>

        <div v-if="sections.myArchived" class="flex flex-col gap-1 mt-1">
          <SidebarChatListItem
            v-for="chat in myArchivedChats"
            :key="chat.id"
            :chat="chat"
            :is-active="chat.id === activeChat"
            @open="openChat"
            @share="handleShare"
            @rename="handleRename"
            @delete="handleDelete"
          />
        </div>
      </div>
    </div>

    <div>
      <button
        @click="toggleSection('widget')"
        class="w-full flex items-center gap-2 px-3 py-2 rounded-lg txt-secondary hover-surface transition-colors text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary min-h-[44px]"
      >
        <ChevronRightIcon :class="['w-4 h-4 transition-transform flex-shrink-0', sections.widget && 'rotate-90']" />
        <span class="text-xs font-medium uppercase tracking-wider">Widget Chats</span>
        <PuzzlePieceIcon class="w-3.5 h-3.5 ml-auto" />
      </button>

      <div v-if="sections.widget" class="flex flex-col gap-1 mt-1">
        <SidebarChatListItem
          v-for="chat in widgetChats"
          :key="chat.id"
          :chat="chat"
          :is-active="chat.id === activeChat"
          @open="openChat"
          @share="handleShare"
          @rename="handleRename"
          @delete="handleDelete"
        />

        <button
          v-if="!showAllWidget && widgetChats.length > 5"
          @click="showAllWidget = true"
          class="px-3 py-2 rounded-lg txt-secondary hover-surface transition-colors text-left text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary min-h-[44px]"
        >
          Show more...
        </button>

        <button
          v-if="widgetArchivedChats.length > 0"
          @click="toggleSection('widgetArchived')"
          class="flex items-center gap-2 px-3 py-2 rounded-lg txt-secondary hover-surface transition-colors text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary min-h-[44px] mt-2"
        >
          <ChevronRightIcon :class="['w-3.5 h-3.5 transition-transform flex-shrink-0', sections.widgetArchived && 'rotate-90']" />
          <span class="text-xs font-medium uppercase tracking-wider">Archived ({{ widgetArchivedChats.length }})</span>
        </button>

        <div v-if="sections.widgetArchived" class="flex flex-col gap-1 mt-1">
          <SidebarChatListItem
            v-for="chat in widgetArchivedChats"
            :key="chat.id"
            :chat="chat"
            :is-active="chat.id === activeChat"
            @open="openChat"
            @share="handleShare"
            @rename="handleRename"
            @delete="handleDelete"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ChevronRightIcon, PuzzlePieceIcon } from '@heroicons/vue/24/outline'
import SidebarChatListItem from './SidebarChatListItem.vue'
import { useChatsStore } from '@/stores/chats'
import { useHistoryStore } from '@/stores/history'
import { useDialog } from '@/composables/useDialog'
import type { Chat } from '@/mocks/chats'

const chatsStore = useChatsStore()
const historyStore = useHistoryStore()
const router = useRouter()
const dialog = useDialog()

const sections = ref({
  my: true,
  widget: false,
  myArchived: false,
  widgetArchived: false
})

const showAllMy = ref(false)
const showAllWidget = ref(false)

// Helper to format dates
const formatDate = (dateString: string): string => {
  const date = new Date(dateString)
  const now = new Date()
  const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000)

  if (diffInSeconds < 60) return 'Just now'
  if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`
  if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`
  if (diffInSeconds < 604800) return `${Math.floor(diffInSeconds / 86400)}d ago`
  return date.toLocaleDateString()
}

// Map API chats to sidebar format
const myChats = computed(() => {
  const apiChats = chatsStore.chats.map(c => ({
    id: String(c.id),
    title: c.title,
    timestamp: formatDate(c.updatedAt),
    type: 'personal' as const,
    archived: false
  }))
  return showAllMy.value ? apiChats : apiChats.slice(0, 5)
})

const myArchivedChats = computed((): Chat[] => {
  // Archived chats not implemented yet
  return []
})

const widgetChats = computed((): Chat[] => {
  // Widget chats not implemented yet
  return []
})

const widgetArchivedChats = computed((): Chat[] => {
  return []
})

const activeChat = computed(() => {
  return chatsStore.activeChatId ? String(chatsStore.activeChatId) : ''
})

const toggleSection = (section: 'my' | 'widget' | 'myArchived' | 'widgetArchived') => {
  sections.value[section] = !sections.value[section]
}

const createNewChat = async () => {
  const newChat = await chatsStore.createChat()
  if (newChat) {
    historyStore.clearHistory()
    // Navigate to chat view if not already there
    if (router.currentRoute.value.path !== '/') {
      router.push('/')
    }
  }
}

const openChat = async (id: string) => {
  const chatId = Number(id)
  chatsStore.setActiveChat(chatId)
  // History will be loaded automatically via watcher in ChatView
  // Navigate to chat view if not already there
  if (router.currentRoute.value.path !== '/') {
    router.push('/')
  }
}

const handleShare = (id: string) => {
  console.log('Share chat:', id)
  // TODO: Implement share functionality
}

const handleRename = async (id: string) => {
  const chat = chatsStore.chats.find(c => c.id === Number(id))
  const newTitle = await dialog.prompt({
    title: 'Rename Chat',
    message: 'Enter a new title for this chat:',
    placeholder: 'Chat title...',
    defaultValue: chat?.title || '',
    confirmText: 'Rename',
    cancelText: 'Cancel'
  })
  
  if (newTitle && newTitle.trim()) {
    chatsStore.updateChatTitle(Number(id), newTitle.trim())
  }
}

const handleDelete = async (id: string) => {
  const confirmed = await dialog.confirm({
    title: 'Delete Chat',
    message: 'Are you sure you want to delete this chat? This action cannot be undone.',
    confirmText: 'Delete',
    cancelText: 'Cancel',
    danger: true
  })
  
  if (confirmed) {
    await chatsStore.deleteChat(Number(id))
  }
}

// Load chats on mount
onMounted(() => {
  chatsStore.loadChats()
})
</script>
