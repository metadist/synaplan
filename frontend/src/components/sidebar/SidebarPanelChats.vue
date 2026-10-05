<template>
  <div
    v-if="isGuest"
    class="flex flex-col gap-3 px-3 pt-2"
    data-testid="section-sidebar-chats-guest"
  >
    <p class="text-sm txt-secondary">{{ $t('guest.banner.subtitle') }}</p>
    <router-link
      :to="configStore.auth.registrationEnabled ? '/register' : '/login'"
      class="btn-primary w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium min-h-11"
      data-testid="btn-sidebar-v2-guest-signup"
    >
      {{ configStore.auth.registrationEnabled ? $t('guest.banner.signUp') : $t('auth.signIn') }}
    </router-link>
  </div>
  <div v-else data-testid="section-sidebar-chats" :data-chats-loading="chatsLoading">
    <div class="sticky top-0 z-10 bg-[var(--bg-sidebar)] px-3 pt-2 pb-2">
      <button
        type="button"
        class="btn-primary w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-medium min-h-11"
        :disabled="isCreatingChat"
        data-testid="btn-sidebar-v2-new-chat"
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
    </div>

    <div v-if="iamSharingEnabled" class="mt-4" data-testid="section-sidebar-incoming">
      <div class="group/section relative">
        <button
          type="button"
          :class="sectionToggleClass"
          :aria-expanded="incomingExpanded"
          aria-controls="sidebar-incoming-list"
          data-testid="btn-sidebar-v2-incoming-toggle"
          :title="$t('iam.incoming.purpose')"
          @click="incomingExpanded = !incomingExpanded"
        >
          <span class="truncate">{{ $t('iam.incoming.menu') }}</span>
          <span
            v-if="incomingStore.hasNew"
            class="text-[11px] font-semibold px-1.5 py-0.5 rounded-full bg-[var(--status-error-muted)] text-[var(--status-error-text)] tabular-nums"
            data-testid="text-sidebar-v2-incoming-count"
            >{{ incomingStore.unseenCount }}</span
          >
          <ChevronDownIcon
            :class="[sectionChevronClass, { '-rotate-90': !incomingExpanded }]"
            aria-hidden="true"
          />
        </button>
        <router-link
          v-if="groupsEnabled"
          to="/groups"
          :class="sectionHoverLinkClass"
          data-testid="btn-sidebar-v2-incoming-groups"
          :title="$t('nav.myGroups')"
          :aria-label="$t('nav.myGroups')"
        >
          <UserGroupIcon class="w-4 h-4" aria-hidden="true" />
        </router-link>
      </div>
      <div v-show="incomingExpanded" id="sidebar-incoming-list" class="px-1">
        <p
          v-if="incomingChatList.length === 0"
          class="px-3 py-2 text-sm txt-secondary"
          data-testid="text-sidebar-v2-incoming-empty"
        >
          {{ $t('iam.incoming.emptyText') }}
        </p>
        <ChatHistoryList
          v-else
          :chats="incomingChatList"
          list-test-id="list-chat-v2-incoming"
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
    </div>

    <div v-if="pinnedChats.length > 0" class="mt-5" data-testid="section-sidebar-pinned">
      <div class="group/section relative">
        <button
          type="button"
          :class="sectionToggleClass"
          :aria-expanded="pinnedExpanded"
          aria-controls="sidebar-pinned-list"
          data-testid="btn-sidebar-v2-pinned-toggle"
          @click="pinnedExpanded = !pinnedExpanded"
        >
          {{ $t('nav.pinned') }}
          <ChevronDownIcon
            :class="[sectionChevronClass, { '-rotate-90': !pinnedExpanded }]"
            aria-hidden="true"
          />
        </button>
      </div>
      <div v-show="pinnedExpanded" id="sidebar-pinned-list" class="px-1">
        <ChatHistoryList
          :chats="pinnedChats"
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
    </div>

    <div class="mt-5">
      <div class="group/section relative">
        <button
          type="button"
          :class="sectionToggleClass"
          :aria-expanded="chatsExpanded"
          aria-controls="sidebar-chat-list"
          data-testid="btn-sidebar-v2-chats-toggle"
          @click="chatsExpanded = !chatsExpanded"
        >
          {{ $t('nav.chats') }}
          <ChevronDownIcon
            :class="[sectionChevronClass, { '-rotate-90': !chatsExpanded }]"
            aria-hidden="true"
          />
        </button>
        <router-link
          to="/chats"
          :class="sectionHoverLinkClass"
          data-testid="btn-chat-v2-show-all"
          :title="$t('chat.showAll')"
          :aria-label="$t('chat.showAll')"
        >
          <ArrowUpRightIcon class="w-4 h-4" aria-hidden="true" />
        </router-link>
      </div>

      <div v-show="chatsExpanded" id="sidebar-chat-list" class="px-1">
        <ChatHistoryList
          :chats="unpinnedChats"
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
            <p
              v-if="chatsReady"
              class="px-3 py-2 text-sm txt-secondary"
              data-testid="text-sidebar-v2-chats-empty"
            >
              {{ $t('nav.chatsEmpty') }}
            </p>
          </template>
        </ChatHistoryList>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import {
  ArrowUpRightIcon,
  ChevronDownIcon,
  PlusIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import ChatHistoryList from './ChatHistoryList.vue'
import { listOverflows, scrollerGrewWithContent } from './chatHistoryPaging'
import { chatsPanelRefresh } from '@/composables/useNavSections'
import { useChatHistory } from '@/composables/useChatHistory'
import { isIamGroupsEnabled } from '@/composables/useIamFeature'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'

const {
  isCreatingChat,
  iamSharingEnabled,
  pinnedChats,
  unpinnedChats,
  incomingChatList,
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

const CHATS_EXPANDED_KEY = 'sidebar-chats-expanded'
const PINNED_EXPANDED_KEY = 'sidebar-pinned-expanded'
const INCOMING_EXPANDED_KEY = 'sidebar-incoming-expanded'

const readExpanded = (key: string): boolean => {
  try {
    return localStorage.getItem(key) !== 'false'
  } catch {
    return true
  }
}

const rememberExpanded = (key: string, open: boolean) => {
  try {
    localStorage.setItem(key, String(open))
  } catch {
    // Private mode can block storage. The section still toggles for this visit.
  }
}

const sectionToggleClass =
  'mb-0.5 flex w-full items-center gap-1.5 bg-transparent px-3 py-1 text-left text-[13px] font-semibold txt-secondary hover:bg-transparent focus:bg-transparent focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--brand)]'

const sectionChevronClass =
  'h-3.5 w-3.5 flex-shrink-0 opacity-0 transition-[opacity,rotate] duration-200 ease-out motion-reduce:transition-none group-hover/section:opacity-100 group-focus-visible/section:opacity-100'

const sectionSideLinkClass =
  'absolute right-2 top-1/2 z-10 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md txt-secondary transition-opacity hover:text-[var(--txt-primary)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]'

const sectionHoverLinkClass =
  sectionSideLinkClass +
  ' pointer-events-none opacity-0 focus-visible:pointer-events-auto focus-visible:opacity-100 group-hover/section:pointer-events-auto group-hover/section:opacity-100'

const groupsEnabled = computed(() => isIamGroupsEnabled())

const authStore = useAuthStore()
const configStore = useConfigStore()
const isGuest = computed(() => !authStore.isAuthenticated)

const chatsExpanded = ref(readExpanded(CHATS_EXPANDED_KEY))
const pinnedExpanded = ref(readExpanded(PINNED_EXPANDED_KEY))
const incomingExpanded = ref(readExpanded(INCOMING_EXPANDED_KEY))
const chatsReady = ref(false)
let filling = false

const chatsLoading = computed(() => (!chatsReady.value || chatsStore.loading ? 'true' : 'false'))

watch(chatsExpanded, (open) => {
  rememberExpanded(CHATS_EXPANDED_KEY, open)
  if (open) void nextTick(() => fillUntilScrollable())
})
watch(pinnedExpanded, (open) => rememberExpanded(PINNED_EXPANDED_KEY, open))
watch(incomingExpanded, (open) => rememberExpanded(INCOMING_EXPANDED_KEY, open))

const scrollRoot = (): HTMLElement | null => {
  const root = document.querySelector('[data-testid="section-sidebar-scroll"]')
  return root instanceof HTMLElement ? root : null
}

/**
 * If the loaded page does not fill the menu, ask for the next page. Stop once
 * the list can scroll, or if the pane grows with the rows (it is not a real
 * scrollport, and continuing would download every chat).
 */
const fillUntilScrollable = async () => {
  if (filling || !chatsExpanded.value || !chatsStore.railHasMore || chatsStore.railLoading) return
  const root = scrollRoot()
  if (!root || root.clientHeight === 0) return
  if (listOverflows(root.clientHeight, root.scrollHeight)) return
  const before = { clientHeight: root.clientHeight, scrollHeight: root.scrollHeight }
  const beforeCount = unpinnedChats.value.length
  filling = true
  try {
    await chatsStore.loadRailChats(false)
  } finally {
    filling = false
  }
  await nextTick()
  const next = scrollRoot()
  if (!next || scrollerGrewWithContent(before, next)) return
  if (unpinnedChats.value.length === beforeCount) return
  await fillUntilScrollable()
}

/** One more page from the server. Further pages wait for the next scroll. */
const showMoreChats = () => {
  if (!chatsExpanded.value) return
  return chatsStore.loadRailChats(false)
}

watch(
  () => unpinnedChats.value.length,
  () => {
    if (chatsReady.value) void nextTick(() => fillUntilScrollable())
  }
)

defineExpose({ showMoreChats })

const refreshChats = async () => {
  // The chats store sends signed-out callers to the login page.
  if (isGuest.value) return
  chatsReady.value = false
  try {
    await Promise.all([chatsStore.loadRailChats(true), incomingStore.load()])
  } finally {
    chatsReady.value = true
    void nextTick(() => fillUntilScrollable())
  }
}

watch(chatsPanelRefresh, () => {
  void refreshChats()
})

watch(isGuest, (guest) => {
  if (!guest) void refreshChats()
})

onMounted(() => {
  void refreshChats()
})
</script>
