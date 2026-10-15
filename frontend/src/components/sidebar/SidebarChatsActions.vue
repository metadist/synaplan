<template>
  <div v-if="!isGuest" class="px-4 pt-2 pb-2" data-testid="section-sidebar-chats-actions">
    <button
      type="button"
      class="btn-primary w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium min-h-11"
      :disabled="isCreatingChat"
      data-testid="btn-sidebar-v2-new-chat"
      data-tour="chat-new"
      @click="createChat()"
    >
      <Icon
        v-if="isCreatingChat"
        icon="mdi:loading"
        class="w-5 h-5 animate-spin"
        aria-hidden="true"
      />
      <PlusIcon v-else class="w-5 h-5" aria-hidden="true" />
      {{ $t('chat.newChat') }}
    </button>
    <button
      type="button"
      class="btn-secondary mt-2 w-full px-4 py-2.5 text-sm font-medium"
      data-testid="btn-sidebar-archived-chats"
      @click="toggleArchivedChats"
    >
      {{ chatsStore.viewingArchived ? $t('chat.showOpenChats') : $t('chat.archivedFilter') }}
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { PlusIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useChatHistory } from '@/composables/useChatHistory'
import { useAuthStore } from '@/stores/auth'
import { useChatsStore } from '@/stores/chats'

const authStore = useAuthStore()
const chatsStore = useChatsStore()
const isGuest = computed(() => !authStore.isAuthenticated)
const { isCreatingChat, createChat, toggleArchivedChats } = useChatHistory()
</script>
