<template>
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="sidebarStore.chatSheetOpen"
        class="modal-overlay fixed inset-0 z-[100] flex items-end sm:items-center justify-center bg-black/40 backdrop-blur-sm"
        data-testid="modal-chat-manager-backdrop"
        @click.self="sidebarStore.closeChatSheet()"
      >
        <div
          class="modal-panel w-full sm:max-w-xl max-h-[85vh] flex flex-col rounded-t-2xl sm:rounded-2xl shadow-2xl overflow-hidden bg-white/95 dark:bg-[#0e1628]/95 backdrop-blur-xl border-t sm:border border-white/20 dark:border-white/[0.08] sm:m-4"
          data-testid="modal-chat-manager"
          :data-chats-loading="chatsStore.loading ? 'true' : 'false'"
          @click.stop
        >
          <div class="sm:hidden flex justify-center pt-2 pb-1">
            <div class="w-10 h-1 rounded-full bg-black/10 dark:bg-white/10" />
          </div>

          <div class="flex-shrink-0 px-4 pt-3 pb-3 sm:px-6 sm:pt-6 sm:pb-4">
            <div class="flex items-center justify-between mb-4">
              <div>
                <h2 class="text-lg font-bold txt-primary leading-tight">{{ $t('chat.recent') }}</h2>
                <p class="text-xs txt-secondary mt-0.5">
                  {{ sheetChats.length }}
                  {{ sheetChats.length === 1 ? $t('chat.conversation') : $t('chat.conversations') }}
                </p>
              </div>
              <button
                type="button"
                class="icon-ghost w-11 h-11 inline-flex items-center justify-center"
                :aria-label="$t('common.close')"
                @click="sidebarStore.closeChatSheet()"
              >
                <Icon icon="mdi:close" class="w-5 h-5" />
              </button>
            </div>

            <div class="flex items-center gap-2">
              <div class="flex-1 relative">
                <Icon
                  icon="mdi:magnify"
                  class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 txt-secondary pointer-events-none"
                />
                <input
                  v-model="searchQuery"
                  type="text"
                  class="w-full pl-9 pr-3 py-2 text-sm rounded-xl bg-black/[0.04] dark:bg-white/[0.04] border border-black/[0.06] dark:border-white/[0.06] txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]/30"
                  :placeholder="$t('chat.browser.searchPlaceholder')"
                />
              </div>
              <button
                type="button"
                class="btn-primary flex-shrink-0 inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-medium min-h-11"
                :disabled="isCreatingChat"
                data-testid="btn-chat-modal-new"
                @click="createChat(true)"
              >
                <Icon
                  :icon="isCreatingChat ? 'mdi:loading' : 'mdi:plus'"
                  :class="['w-4 h-4', isCreatingChat && 'animate-spin']"
                />
                <span class="hidden sm:inline">{{ $t('chat.newChat') }}</span>
              </button>
            </div>

            <div v-if="iamSharingEnabled" class="mt-3" data-testid="section-chat-manager-filter">
              <ChatKindFilter
                v-model="kindFilter"
                :counts="kindCounts"
                :new-count="incomingStore.unseenCount"
              />
            </div>
          </div>

          <div class="flex-1 overflow-y-auto scroll-thin px-3 pb-4 sm:px-4">
            <div v-if="filteredPinned.length > 0" data-testid="section-chat-sheet-pinned">
              <h3
                class="mb-0.5 flex items-center min-h-9 px-2 py-1.5 text-xs font-semibold txt-secondary"
              >
                {{ $t('nav.pinned') }}
              </h3>
              <ChatHistoryList
                :chats="filteredPinned"
                list-test-id="list-chat-v2-pinned"
                :active-chat-id="chatsStore.activeChatId"
                :title-of="displayTitle"
                :time-of="(chat) => formatTimestamp(chat.updatedAt || chat.createdAt)"
                :generating="isGenerating"
                @select="selectChat"
                @share="shareChat"
                @rename="renameChat"
                @delete="deleteChat"
                @pin="toggleChatPin"
              />
            </div>
            <ChatHistoryList
              v-if="filteredChats.length > 0 || filteredPinned.length === 0"
              :chats="filteredChats"
              :active-chat-id="chatsStore.activeChatId"
              :title-of="displayTitle"
              :time-of="(chat) => formatTimestamp(chat.updatedAt || chat.createdAt)"
              :generating="isGenerating"
              @select="selectChat"
              @share="shareChat"
              @rename="renameChat"
              @delete="deleteChat"
              @pin="toggleChatPin"
            >
              <template #empty>
                <p class="text-sm txt-secondary text-center py-10">
                  {{
                    searchQuery || kindFilter !== 'all'
                      ? $t('common.noResults')
                      : $t('chat.noChats')
                  }}
                </p>
              </template>
            </ChatHistoryList>
          </div>

          <div
            class="flex-shrink-0 px-4 py-3 sm:px-5 border-t border-black/[0.04] dark:border-white/[0.04]"
          >
            <button
              type="button"
              class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium text-[var(--brand)] bg-[var(--brand)]/[0.06] hover:bg-[var(--brand)]/[0.12] min-h-11"
              @click="showAll"
            >
              {{ $t('chat.showAll') }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useSidebarStore } from '@/stores/sidebar'
import { useChatHistory } from '@/composables/useChatHistory'
import { matchesChatFilter, type ChatListFilter } from '@/utils/chatKind'
import ChatHistoryList from './ChatHistoryList.vue'
import ChatKindFilter from '@/components/iam/ChatKindFilter.vue'

const router = useRouter()
const sidebarStore = useSidebarStore()
const searchQuery = ref('')
const kindFilter = ref<ChatListFilter>('all')

const {
  isCreatingChat,
  iamSharingEnabled,
  ownChatList,
  pinnedChats,
  incomingChatList,
  sheetChats,
  displayTitle,
  formatTimestamp,
  isGenerating,
  createChat,
  selectChat,
  renameChat,
  toggleChatPin,
  deleteChat,
  shareChat,
  chatsStore,
  incomingStore,
} = useChatHistory()

const matchesSheet = (chat: (typeof sheetChats.value)[number]) => {
  const query = searchQuery.value.toLowerCase().trim()
  if (!matchesChatFilter(chat.kind, kindFilter.value)) return false
  return !query || displayTitle(chat).toLowerCase().includes(query)
}

const filteredPinned = computed(() => pinnedChats.value.filter(matchesSheet))

const filteredChats = computed(() => sheetChats.value.filter(matchesSheet))

const kindCounts = computed(() => ({
  private: ownChatList.value.length,
  group: incomingChatList.value.length,
}))

watch(
  () => sidebarStore.chatSheetOpen,
  (open) => {
    if (!open) return
    searchQuery.value = ''
    kindFilter.value = 'all'
    void chatsStore.loadChats()
    void incomingStore.load()
  }
)

const showAll = () => {
  sidebarStore.closeChatSheet()
  router.push('/chats')
}

const onEscape = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && sidebarStore.chatSheetOpen) {
    sidebarStore.closeChatSheet()
  }
}

onMounted(() => document.addEventListener('keydown', onEscape))
onBeforeUnmount(() => document.removeEventListener('keydown', onEscape))
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
</style>
