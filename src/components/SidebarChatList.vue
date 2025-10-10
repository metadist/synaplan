<template>
  <div class="flex flex-col gap-2">
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
import { ref, computed } from 'vue'
import { ChevronRightIcon, PuzzlePieceIcon } from '@heroicons/vue/24/outline'
import SidebarChatListItem from './SidebarChatListItem.vue'
import { mockChats, type Chat } from '@/mocks/chats'

const chats = ref<Chat[]>(mockChats)

const sections = ref({
  my: true,
  widget: false,
  myArchived: false,
  widgetArchived: false
})

const showAllMy = ref(false)
const showAllWidget = ref(false)
const activeChat = ref('1')

const myChats = computed(() => {
  const filtered = chats.value.filter(c => c.type === 'personal' && !c.archived)
  return showAllMy.value ? filtered : filtered.slice(0, 5)
})

const myArchivedChats = computed(() => {
  return chats.value.filter(c => c.type === 'personal' && c.archived)
})

const widgetChats = computed(() => {
  const filtered = chats.value.filter(c => c.type === 'widget' && !c.archived)
  return showAllWidget.value ? filtered : filtered.slice(0, 5)
})

const widgetArchivedChats = computed(() => {
  return chats.value.filter(c => c.type === 'widget' && c.archived)
})

const toggleSection = (section: 'my' | 'widget' | 'myArchived' | 'widgetArchived') => {
  sections.value[section] = !sections.value[section]
}

const openChat = (id: string) => {
  activeChat.value = id
  console.log('Opening chat:', id)
}

const handleShare = (id: string) => {
  console.log('Share chat:', id)
}

const handleRename = (id: string) => {
  console.log('Rename chat:', id)
}

const handleDelete = (id: string) => {
  const index = chats.value.findIndex(c => c.id === id)
  if (index !== -1) {
    chats.value.splice(index, 1)
    console.log('Deleted chat:', id)
  }
}
</script>
