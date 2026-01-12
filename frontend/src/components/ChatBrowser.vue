<template>
  <div class="space-y-6" data-testid="comp-chat-browser">
    <!-- Header with Stats -->
    <div class="flex flex-col gap-4">
      <div class="flex items-center gap-3">
        <div class="p-3 rounded-xl bg-brand/10">
          <ChatBubbleLeftRightIcon class="w-6 h-6 txt-brand" />
        </div>
        <div class="flex-1">
          <h2 class="text-2xl font-semibold txt-primary mb-1">
            {{ $t('chat.browser.title') }}
          </h2>
          <p class="txt-secondary text-sm">
            {{ $t('chat.browser.description') }}
          </p>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="surface-card p-4 flex items-center gap-3">
          <div class="p-2 rounded-lg bg-blue-500/10">
            <ChatBubbleLeftRightIcon class="w-5 h-5 text-blue-500" />
          </div>
          <div>
            <div class="text-2xl font-bold txt-primary">{{ totalChatsCount }}</div>
            <div class="text-xs txt-secondary">
              {{ $t('chat.browser.totalChats', { count: totalChatsCount }) }}
            </div>
          </div>
        </div>
        <div class="surface-card p-4 flex items-center gap-3">
          <div class="p-2 rounded-lg bg-purple-500/10">
            <PuzzlePieceIcon class="w-5 h-5 text-purple-500" />
          </div>
          <div>
            <div class="text-2xl font-bold txt-primary">{{ widgetChatsCount }}</div>
            <div class="text-xs txt-secondary">Widget Chats</div>
          </div>
        </div>
        <div class="surface-card p-4 flex items-center gap-3">
          <div class="p-2 rounded-lg bg-green-500/10">
            <UserIcon class="w-5 h-5 text-green-500" />
          </div>
          <div>
            <div class="text-2xl font-bold txt-primary">{{ myChatsCount }}</div>
            <div class="text-xs txt-secondary">{{ $t('chat.browser.myChats') }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Search and Filters -->
    <div class="surface-card p-5 space-y-4">
      <!-- Search Bar -->
      <div class="relative">
        <div class="absolute left-3 top-1/2 -translate-y-1/2 flex items-center gap-2">
          <MagnifyingGlassIcon class="w-5 h-5 txt-secondary" />
        </div>
        <input
          v-model="searchQuery"
          type="text"
          :placeholder="$t('chat.browser.searchPlaceholder')"
          class="w-full pl-10 pr-4 py-3 bg-app border border-light-border dark:border-dark-border rounded-lg txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-primary transition-all"
          data-testid="input-search-chats"
        />
        <button
          v-if="searchQuery"
          class="absolute right-3 top-1/2 -translate-y-1/2 p-1 rounded hover:bg-black/5 dark:hover:bg-white/5"
          @click="searchQuery = ''"
        >
          <XMarkIcon class="w-4 h-4 txt-secondary" />
        </button>
      </div>

      <!-- Filter Row -->
      <div class="flex flex-col sm:flex-row gap-3">
        <!-- Type Filter -->
        <div class="flex-1">
          <label class="flex items-center gap-2 text-xs font-medium txt-secondary mb-2">
            <FunnelIcon class="w-3.5 h-3.5" />
            {{ $t('chat.browser.filterByType') }}
          </label>
          <select
            v-model="selectedType"
            class="w-full px-3 py-2.5 bg-app border border-light-border dark:border-dark-border rounded-lg txt-primary focus:outline-none focus:ring-2 focus:ring-primary transition-all"
            data-testid="select-type-filter"
          >
            <option value="all">{{ $t('chat.browser.allTypes') }}</option>
            <option value="widget">{{ $t('chat.browser.widgetChats') }}</option>
            <option value="my">{{ $t('chat.browser.myChats') }}</option>
          </select>
        </div>

        <!-- Date Filter -->
        <div class="flex-1">
          <label class="flex items-center gap-2 text-xs font-medium txt-secondary mb-2">
            <CalendarIcon class="w-3.5 h-3.5" />
            {{ $t('chat.browser.filterByDate') }}
          </label>
          <select
            v-model="selectedDateRange"
            class="w-full px-3 py-2.5 bg-app border border-light-border dark:border-dark-border rounded-lg txt-primary focus:outline-none focus:ring-2 focus:ring-primary transition-all"
            data-testid="select-date-filter"
          >
            <option value="all">{{ $t('chat.browser.allDates') }}</option>
            <option value="today">{{ $t('chat.browser.today') }}</option>
            <option value="yesterday">{{ $t('chat.browser.yesterday') }}</option>
            <option value="lastWeek">{{ $t('chat.browser.lastWeek') }}</option>
            <option value="lastMonth">{{ $t('chat.browser.lastMonth') }}</option>
            <option value="older">{{ $t('chat.browser.older') }}</option>
          </select>
        </div>

        <!-- Sort -->
        <div class="flex-1">
          <label class="flex items-center gap-2 text-xs font-medium txt-secondary mb-2">
            <ArrowsUpDownIcon class="w-3.5 h-3.5" />
            {{ $t('chat.browser.sortBy') }}
          </label>
          <select
            v-model="sortBy"
            class="w-full px-3 py-2.5 bg-app border border-light-border dark:border-dark-border rounded-lg txt-primary focus:outline-none focus:ring-2 focus:ring-primary transition-all"
            data-testid="select-sort"
          >
            <option value="newest">{{ $t('chat.browser.sortNewest') }}</option>
            <option value="oldest">{{ $t('chat.browser.sortOldest') }}</option>
            <option value="mostMessages">{{ $t('chat.browser.sortMostMessages') }}</option>
          </select>
        </div>
      </div>

      <!-- Active Filters Display -->
      <div v-if="hasActiveFilters" class="flex items-center gap-2 flex-wrap">
        <span class="text-xs txt-secondary flex items-center gap-1">
          <FunnelIcon class="w-3.5 h-3.5" />
          {{ $t('chat.browser.activeFilters') }}:
        </span>
        <button
          v-if="selectedType !== 'all'"
          class="pill txt-secondary text-xs flex items-center gap-1.5 hover:bg-red-500/10 hover:text-red-500 transition-colors"
          @click="selectedType = 'all'"
        >
          <PuzzlePieceIcon v-if="selectedType === 'widget'" class="w-3 h-3" />
          <UserIcon v-else class="w-3 h-3" />
          {{
            selectedType === 'widget' ? $t('chat.browser.widgetChats') : $t('chat.browser.myChats')
          }}
          <XMarkIcon class="w-3 h-3" />
        </button>
        <button
          v-if="selectedDateRange !== 'all'"
          class="pill txt-secondary text-xs flex items-center gap-1.5 hover:bg-red-500/10 hover:text-red-500 transition-colors"
          @click="selectedDateRange = 'all'"
        >
          <CalendarIcon class="w-3 h-3" />
          {{ $t(`chat.browser.${selectedDateRange}`) }}
          <XMarkIcon class="w-3 h-3" />
        </button>
        <button
          v-if="searchQuery"
          class="pill txt-secondary text-xs flex items-center gap-1.5 hover:bg-red-500/10 hover:text-red-500 transition-colors"
          @click="searchQuery = ''"
        >
          <MagnifyingGlassIcon class="w-3 h-3" />
          "{{ searchQuery.slice(0, 20) }}{{ searchQuery.length > 20 ? '...' : '' }}"
          <XMarkIcon class="w-3 h-3" />
        </button>
        <button
          class="text-xs txt-brand hover:underline flex items-center gap-1"
          @click="clearAllFilters"
        >
          <XMarkIcon class="w-3.5 h-3.5" />
          {{ $t('chat.browser.clearAll') }}
        </button>
      </div>
    </div>

    <!-- Results Count -->
    <div
      v-if="filteredChats.length > 0"
      class="flex items-center justify-between txt-secondary text-sm"
    >
      <span>
        {{
          $t('chat.browser.showing', {
            start: startIndex + 1,
            end: endIndex,
            total: filteredChats.length,
          })
        }}
      </span>
      <span>
        {{ $t('chat.browser.page', { current: currentPage, total: totalPages }) }}
      </span>
    </div>

    <!-- Results -->
    <div v-if="paginatedChats.length > 0" class="space-y-3">
      <div
        v-for="chat in paginatedChats"
        :key="chat.id"
        class="surface-card p-5 hover-surface transition-all cursor-pointer group border-2 border-transparent hover:border-brand/20"
        data-testid="chat-item"
        @click="openChat(chat.id)"
      >
        <div class="flex items-start justify-between gap-4">
          <div class="flex-1 min-w-0">
            <!-- Title and Type Badge -->
            <div class="flex items-center gap-2 mb-2">
              <div
                v-if="chat.type === 'widget'"
                class="flex items-center gap-1.5 px-2 py-1 rounded-md bg-purple-500/10 text-purple-600 dark:text-purple-400"
              >
                <PuzzlePieceIcon class="w-3.5 h-3.5" />
                <span class="text-xs font-medium">Widget</span>
              </div>
              <div
                v-else
                class="flex items-center gap-1.5 px-2 py-1 rounded-md bg-green-500/10 text-green-600 dark:text-green-400"
              >
                <UserIcon class="w-3.5 h-3.5" />
                <span class="text-xs font-medium">{{ $t('chat.browser.myChats') }}</span>
              </div>
            </div>

            <!-- Chat Title -->
            <h3
              class="text-base font-semibold txt-primary mb-2 truncate group-hover:txt-brand transition-colors"
            >
              {{ chat.title }}
            </h3>

            <!-- Meta Information -->
            <div class="flex items-center gap-4 txt-secondary text-sm">
              <span class="flex items-center gap-1.5">
                <ChatBubbleLeftIcon class="w-4 h-4" />
                <span class="font-medium">{{ chat.messageCount }}</span>
                <span class="hidden sm:inline">{{
                  $t('chat.browser.messages', { count: chat.messageCount })
                }}</span>
              </span>
              <span class="flex items-center gap-1.5">
                <ClockIcon class="w-4 h-4" />
                {{ formatDate(chat.lastMessage) }}
              </span>
            </div>
          </div>

          <!-- Action Button -->
          <div class="flex items-center gap-2 flex-shrink-0">
            <div
              class="px-3 py-2 rounded-lg bg-brand/10 txt-brand opacity-0 group-hover:opacity-100 transition-opacity"
            >
              <span class="text-sm font-medium">{{ $t('common.open') }}</span>
            </div>
            <ChevronRightIcon
              class="w-5 h-5 txt-secondary group-hover:txt-brand transition-all group-hover:translate-x-1"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <div
      v-if="filteredChats.length > 0 && totalPages > 1"
      class="flex items-center justify-center gap-2"
      data-testid="pagination"
    >
      <!-- Previous Button -->
      <button
        :disabled="currentPage === 1"
        class="px-3 py-2 rounded-lg txt-secondary hover-surface transition-all disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-primary"
        data-testid="btn-prev-page"
        @click="goToPage(currentPage - 1)"
      >
        <ChevronLeftIcon class="w-5 h-5" />
      </button>

      <!-- First Page -->
      <button
        v-if="currentPage > 3"
        class="px-3 py-2 rounded-lg txt-secondary hover-surface transition-all focus:outline-none focus:ring-2 focus:ring-primary min-w-[44px]"
        @click="goToPage(1)"
      >
        1
      </button>
      <span v-if="currentPage > 3" class="txt-secondary">...</span>

      <!-- Page Numbers -->
      <button
        v-for="page in visiblePages"
        :key="page"
        :class="[
          'px-3 py-2 rounded-lg transition-all focus:outline-none focus:ring-2 focus:ring-primary min-w-[44px]',
          page === currentPage
            ? 'bg-primary text-white font-medium'
            : 'txt-secondary hover-surface',
        ]"
        :data-testid="`btn-page-${page}`"
        @click="goToPage(page)"
      >
        {{ page }}
      </button>

      <!-- Last Page -->
      <span v-if="currentPage < totalPages - 2" class="txt-secondary">...</span>
      <button
        v-if="currentPage < totalPages - 2"
        class="px-3 py-2 rounded-lg txt-secondary hover-surface transition-all focus:outline-none focus:ring-2 focus:ring-primary min-w-[44px]"
        @click="goToPage(totalPages)"
      >
        {{ totalPages }}
      </button>

      <!-- Next Button -->
      <button
        :disabled="currentPage === totalPages"
        class="px-3 py-2 rounded-lg txt-secondary hover-surface transition-all disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus:ring-2 focus:ring-primary"
        data-testid="btn-next-page"
        @click="goToPage(currentPage + 1)"
      >
        <ChevronRightIcon class="w-5 h-5" />
      </button>
    </div>

    <!-- Empty State -->
    <div
      v-else-if="filteredChats.length === 0"
      class="surface-card p-12 text-center"
      data-testid="no-results"
    >
      <ChatBubbleLeftRightIcon class="w-16 h-16 mx-auto mb-4 txt-secondary opacity-50" />
      <h3 class="text-lg font-medium txt-primary mb-2">
        {{ $t('chat.browser.noResults') }}
      </h3>
      <p class="txt-secondary">
        {{ $t('chat.browser.noResultsDesc') }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  MagnifyingGlassIcon,
  ChatBubbleLeftRightIcon,
  ChatBubbleLeftIcon,
  PuzzlePieceIcon,
  UserIcon,
  ChevronRightIcon,
  ChevronLeftIcon,
  XMarkIcon,
  FunnelIcon,
  CalendarIcon,
  ArrowsUpDownIcon,
  ClockIcon,
} from '@heroicons/vue/24/outline'
import { useChatsStore } from '@/stores/chats'
import { useI18n } from 'vue-i18n'

const chatsStore = useChatsStore()
const router = useRouter()
const { t } = useI18n()

// Filter states
const searchQuery = ref('')
const selectedType = ref<'all' | 'widget' | 'my'>('all')
const selectedDateRange = ref<'all' | 'today' | 'yesterday' | 'lastWeek' | 'lastMonth' | 'older'>(
  'all'
)
const sortBy = ref<'newest' | 'oldest' | 'mostMessages'>('newest')

// Pagination states
const currentPage = ref(1)
const itemsPerPage = 10

// Format date helper
const formatDate = (timestamp: number | string | undefined): string => {
  if (!timestamp) return t('common.unknown')

  const date = typeof timestamp === 'number' ? new Date(timestamp * 1000) : new Date(timestamp)
  const now = new Date()
  const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000)

  if (diffInSeconds < 60) return t('common.justNow')
  if (diffInSeconds < 3600) {
    const minutes = Math.floor(diffInSeconds / 60)
    return t('common.minutesAgo', { count: minutes })
  }
  if (diffInSeconds < 86400) {
    const hours = Math.floor(diffInSeconds / 3600)
    return t('common.hoursAgo', { count: hours })
  }
  if (diffInSeconds < 604800) {
    const days = Math.floor(diffInSeconds / 86400)
    return t('common.daysAgo', { count: days })
  }
  return date.toLocaleDateString()
}

// Get date range filter
const isInDateRange = (timestamp: number | string | undefined): boolean => {
  if (selectedDateRange.value === 'all') return true
  if (!timestamp) return selectedDateRange.value === 'older'

  const date = typeof timestamp === 'number' ? new Date(timestamp * 1000) : new Date(timestamp)
  const now = new Date()
  const diffInDays = Math.floor((now.getTime() - date.getTime()) / (1000 * 60 * 60 * 24))

  switch (selectedDateRange.value) {
    case 'today':
      return diffInDays === 0
    case 'yesterday':
      return diffInDays === 1
    case 'lastWeek':
      return diffInDays > 1 && diffInDays <= 7
    case 'lastMonth':
      return diffInDays > 7 && diffInDays <= 30
    case 'older':
      return diffInDays > 30
    default:
      return true
  }
}

interface ChatItem {
  id: number
  title: string
  type: 'widget' | 'my'
  messageCount: number
  lastMessage: number | string | undefined
}

// Compute all chats from store
const allChats = computed((): ChatItem[] => {
  return chatsStore.chats.map((c) => {
    if (c.widgetSession) {
      const session = c.widgetSession
      const shortId = session.sessionId.slice(-6)
      const title = `${session.widgetName ?? 'Widget'} • ${shortId}`
      const lastTimestamp =
        session.lastMessage ?? Math.floor(new Date(c.updatedAt).getTime() / 1000)

      return {
        id: c.id,
        title,
        type: 'widget' as const,
        messageCount: session.messageCount,
        lastMessage: lastTimestamp,
      }
    } else {
      return {
        id: c.id,
        title: c.title || 'Untitled Chat',
        type: 'my' as const,
        messageCount: c.messageCount ?? 0,
        lastMessage: Math.floor(new Date(c.updatedAt).getTime() / 1000),
      }
    }
  })
})

// Filter and sort chats
const filteredChats = computed((): ChatItem[] => {
  let result = allChats.value

  // Filter by type
  if (selectedType.value !== 'all') {
    result = result.filter((c) => c.type === selectedType.value)
  }

  // Filter by search query
  if (searchQuery.value.trim()) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter((c) => c.title.toLowerCase().includes(query))
  }

  // Filter by date range
  result = result.filter((c) => isInDateRange(c.lastMessage))

  // Sort
  result = [...result].sort((a, b) => {
    switch (sortBy.value) {
      case 'newest': {
        const aTime =
          typeof a.lastMessage === 'number'
            ? a.lastMessage
            : new Date(a.lastMessage || 0).getTime() / 1000
        const bTime =
          typeof b.lastMessage === 'number'
            ? b.lastMessage
            : new Date(b.lastMessage || 0).getTime() / 1000
        return bTime - aTime
      }
      case 'oldest': {
        const aTime =
          typeof a.lastMessage === 'number'
            ? a.lastMessage
            : new Date(a.lastMessage || 0).getTime() / 1000
        const bTime =
          typeof b.lastMessage === 'number'
            ? b.lastMessage
            : new Date(b.lastMessage || 0).getTime() / 1000
        return aTime - bTime
      }
      case 'mostMessages':
        return b.messageCount - a.messageCount
      default:
        return 0
    }
  })

  return result
})

const totalChatsCount = computed(() => allChats.value.length)

const widgetChatsCount = computed(() => allChats.value.filter((c) => c.type === 'widget').length)

const myChatsCount = computed(() => allChats.value.filter((c) => c.type === 'my').length)

const hasActiveFilters = computed(() => {
  return (
    selectedType.value !== 'all' ||
    selectedDateRange.value !== 'all' ||
    searchQuery.value.trim() !== ''
  )
})

const clearAllFilters = () => {
  selectedType.value = 'all'
  selectedDateRange.value = 'all'
  searchQuery.value = ''
  sortBy.value = 'newest'
}

// Pagination computed values
const totalPages = computed(() => Math.ceil(filteredChats.value.length / itemsPerPage))

const startIndex = computed(() => (currentPage.value - 1) * itemsPerPage)
const endIndex = computed(() =>
  Math.min(startIndex.value + itemsPerPage, filteredChats.value.length)
)

const paginatedChats = computed(() => {
  return filteredChats.value.slice(startIndex.value, endIndex.value)
})

const visiblePages = computed(() => {
  const pages: number[] = []
  const start = Math.max(1, currentPage.value - 2)
  const end = Math.min(totalPages.value, currentPage.value + 2)

  for (let i = start; i <= end; i++) {
    pages.push(i)
  }

  return pages
})

// Pagination methods
const goToPage = (page: number) => {
  if (page >= 1 && page <= totalPages.value) {
    currentPage.value = page
    // Scroll to top of results
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}

// Reset to page 1 when filters change
watch([searchQuery, selectedType, selectedDateRange, sortBy], () => {
  currentPage.value = 1
})

const openChat = (id: number) => {
  chatsStore.setActiveChat(id)
  router.push('/')
}

onMounted(async () => {
  await chatsStore.loadChats()
})
</script>
