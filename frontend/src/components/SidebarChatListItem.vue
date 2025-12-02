<template>
  <div
    ref="root"
    class="group flex items-center gap-2 px-3 py-2 transition-colors relative nav-item"
    :class="isActive ? 'nav-item--active' : ''"
    data-testid="item-chat-list-entry"
  >
    <button
      class="flex-1 text-left text-sm truncate min-h-[36px] flex flex-col justify-center"
      @click="$emit('open', chat.id)"
      data-testid="btn-chat-entry-open"
    >
      <span class="truncate">{{ chat.title }}</span>
      <span class="text-xs txt-secondary">{{ chat.timestamp }}</span>
    </button>

    <div class="relative">
      <button
        class="icon-ghost transition-opacity"
        @click.stop="toggleMenu"
        aria-label="More options"
        data-testid="btn-chat-entry-menu"
      >
        <span class="text-lg leading-none">⋯</span>
      </button>

      <div v-if="isMenuOpen" class="absolute right-0 mt-2 w-44 dropdown-panel z-30">
        <button
          class="dropdown-item"
          @click.stop="handleAction('share')"
          data-testid="btn-chat-entry-share"
        >
          Share
        </button>
        <button
          class="dropdown-item"
          @click.stop="handleAction('rename')"
          data-testid="btn-chat-entry-rename"
        >
          Rename
        </button>
        <button
          class="dropdown-item dropdown-item--danger"
          @click.stop="handleAction('delete')"
          data-testid="btn-chat-entry-delete"
        >
          Delete
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue'

interface Chat {
  id: string
  title: string
  timestamp: string
}

const props = defineProps<{
  chat: Chat
  isActive?: boolean
}>()

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
