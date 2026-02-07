<template>
  <div
    ref="root"
    class="group flex items-center gap-2 px-3 py-2 transition-colors relative nav-item"
    :class="isActive ? 'nav-item--active' : ''"
    data-testid="item-chat-list-entry"
  >
    <!-- Channel icon -->
    <Icon
      v-if="channelIcon"
      :icon="channelIcon"
      class="w-4 h-4 flex-shrink-0"
      :class="channelIconClass"
    />

    <button
      class="flex-1 text-left text-sm truncate min-h-[36px] flex flex-col justify-center"
      data-testid="btn-chat-entry-open"
      @click="$emit('open', chat.id)"
    >
      <span class="truncate">{{ chat.title }}</span>
      <span class="text-xs txt-secondary">{{ chat.timestamp }}</span>
    </button>

    <div class="relative">
      <button
        class="icon-ghost transition-opacity"
        aria-label="More options"
        data-testid="btn-chat-entry-menu"
        @click.stop="toggleMenu"
      >
        <span class="text-lg leading-none">⋯</span>
      </button>

      <div v-if="isMenuOpen" class="absolute right-0 mt-2 w-44 dropdown-panel z-30">
        <button
          class="dropdown-item"
          data-testid="btn-chat-entry-share"
          @click.stop="handleAction('share')"
        >
          Share
        </button>
        <button
          class="dropdown-item"
          data-testid="btn-chat-entry-rename"
          @click.stop="handleAction('rename')"
        >
          Rename
        </button>
        <button
          class="dropdown-item dropdown-item--danger"
          data-testid="btn-chat-entry-delete"
          @click.stop="handleAction('delete')"
        >
          Delete
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Icon } from '@iconify/vue'

interface Chat {
  id: string
  title: string
  timestamp: string
  source?: 'web' | 'whatsapp' | 'email' | 'widget'
}

const props = defineProps<{
  chat: Chat
  isActive?: boolean
}>()

const channelIcon = computed(() => {
  switch (props.chat.source) {
    case 'whatsapp': return 'mdi:whatsapp'
    case 'email': return 'mdi:email-outline'
    case 'widget': return 'mdi:widgets-outline'
    default: return null // web chats don't need an icon
  }
})

const channelIconClass = computed(() => {
  switch (props.chat.source) {
    case 'whatsapp': return 'text-green-500'
    case 'email': return 'text-blue-500'
    case 'widget': return 'text-purple-500'
    default: return ''
  }
})

const emit = defineEmits<{
  open: [id: string]
  share: [id: string]
  rename: [id: string]
  delete: [id: string]
}>()

const root = ref<HTMLElement | null>(null)
const isMenuOpen = ref(false)

const toggleMenu = () => {
  isMenuOpen.value = !isMenuOpen.value
}

const handleAction = (action: 'share' | 'rename' | 'delete') => {
  if (action === 'share') {
    emit('share', props.chat.id)
  } else if (action === 'rename') {
    emit('rename', props.chat.id)
  } else {
    emit('delete', props.chat.id)
  }
  isMenuOpen.value = false
}

const handleClickOutside = (event: MouseEvent) => {
  if (root.value && !root.value.contains(event.target as Node)) {
    isMenuOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>
