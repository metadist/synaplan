<template>
  <div
    :class="['flex gap-4 p-4 text-[16px] leading-6', role === 'user' ? 'justify-end' : '', isSuperseded && 'opacity-50']"
    data-testid="message-container"
  >
    <!-- Avatar with provider logo for assistant -->
    <div
      v-if="role === 'assistant'"
      class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 surface-card"
    >
      <GroqIcon 
        v-if="displayProvider.toLowerCase().includes('groq')"
        :size="24" 
        class-name="" 
      />
      <Icon 
        v-else
        :icon="getProviderIcon(displayProvider)" 
        class="w-6 h-6" 
      />
    </div>

    <!-- Wrapper for thinking blocks + bubble -->
    <div class="flex flex-col max-w-3xl gap-2">
      <!-- Thinking blocks (ABOVE bubble, only for assistant) -->
      <template v-if="role === 'assistant'">
        <MessagePart
          v-for="(part, index) in thinkingParts"
          :key="`thinking-${index}`"
          :part="part"
        />
      </template>

      <!-- Single bubble with content + footer -->
      <div
        :class="['flex flex-col', role === 'user' ? 'bubble-user' : 'bubble-ai']"
        :data-testid="role === 'user' ? 'user-message-bubble' : 'assistant-message-bubble'"
      >
        <!-- Processing Status (inside bubble, before content) -->
        <div
          v-if="isStreaming && processingStatus && role === 'assistant'"
          class="px-4 pt-3 pb-3 processing-enter"
          data-testid="loading-typing-indicator"
        >
        <div class="flex items-center gap-3">
          <svg class="w-5 h-5 animate-spin txt-brand flex-shrink-0" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
          </svg>
          <div class="flex-1 min-w-0">
            <template v-if="processingStatus === 'started'">
              <div class="font-medium">{{ $t('processing.startedTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">{{ $t('processing.startedDesc') }}</div>
            </template>
            <template v-else-if="processingStatus === 'preprocessing'">
              <div class="font-medium">{{ $t('processing.preprocessingTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">{{ $t('processing.preprocessingDesc') }}</div>
            </template>
            <template v-else-if="processingStatus === 'classifying'">
              <div class="font-medium animate-pulse">{{ $t('processing.classifyingTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">
                {{ $t('processing.classifyingDesc') }}
                <span v-if="processingMetadata?.model_name || processingMetadata?.provider" class="txt-brand">
                  · {{ processingMetadata.model_name || processingMetadata.provider }}
                </span>
              </div>
            </template>
            <template v-else-if="processingStatus === 'classified'">
              <div class="font-medium">{{ $t('processing.classifiedTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5 flex items-center gap-1.5 flex-wrap">
                <span>{{ $t('processing.topic') }}:</span>
                <span class="txt-brand font-medium">{{ processingMetadata?.topic || 'general' }}</span>
                <span v-if="processingMetadata?.language" class="opacity-50">·</span>
                <span v-if="processingMetadata?.language">
                  {{ $t('processing.language') }}: <span class="font-medium">{{ processingMetadata.language.toUpperCase() }}</span>
                </span>
                <span v-if="processingMetadata?.model_name" class="opacity-50">·</span>
                <span v-if="processingMetadata?.model_name" class="txt-tertiary text-xs">
                  via {{ processingMetadata.model_name }}
                </span>
              </div>
            </template>
            <template v-else-if="processingStatus === 'searching'">
              <div class="font-medium animate-pulse">{{ $t('processing.searchingTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">
                {{ processingMetadata?.customMessage || $t('processing.searchingDesc') }}
              </div>
            </template>
            <template v-else-if="processingStatus === 'search_complete'">
              <div class="font-medium">{{ $t('processing.searchCompleteTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">
                {{ $t('processing.searchCompleteDesc') }}
                <span v-if="processingMetadata?.results_count" class="txt-brand font-medium">
                  · {{ processingMetadata.results_count }} {{ $t('processing.results') }}
                </span>
              </div>
            </template>
            <template v-else-if="processingStatus === 'analyzing'">
              <div class="font-medium animate-pulse">{{ $t('processing.analyzingTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">
                {{ processingMetadata?.customMessage || $t('processing.analyzingDesc') }}
              </div>
            </template>
            <template v-else-if="processingStatus === 'processing'">
              <div class="font-medium">{{ $t('processing.routingTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">
                {{ $t('processing.routingDesc') }}
                <span v-if="processingMetadata?.handler" class="txt-brand font-medium">
                  {{ processingMetadata.handler }}
                </span>
              </div>
            </template>
            <template v-else-if="processingStatus === 'generating'">
              <div class="font-medium animate-pulse">{{ $t('processing.generatingTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">
                <template v-if="processingMetadata?.customMessage">
                  {{ processingMetadata.customMessage }}
                </template>
                <template v-else>
                  {{ $t('processing.generatingDesc') }}
                  <span v-if="processingMetadata?.model_name || processingMetadata?.provider" class="txt-brand">
                    · {{ processingMetadata.model_name || processingMetadata.provider }}
                  </span>
                </template>
              </div>
            </template>
            <template v-else-if="processingStatus === 'generating_file'">
              <div class="font-medium animate-pulse">{{ $t('processing.generatingFileTitle') }}</div>
              <div class="text-sm txt-tertiary mt-0.5">
                {{ processingMetadata?.customMessage || $t('processing.generatingFileDesc') }}
              </div>
            </template>
          </div>
        </div>
      </div>
      
        <!-- Bubble content (only non-thinking parts) -->
        <div class="px-4 py-3 overflow-hidden space-y-3">
          <!-- Combined Badges: Files + Web Search (NEW) -->
          <div v-if="(files && files.length > 0) || webSearch" class="space-y-2">
            <!-- Show badges with smart collapsing -->
            <div class="flex flex-wrap gap-2">
              <!-- Files (show based on collapse state) -->
              <template v-if="files && files.length > 0">
                <div
                  v-for="file in showAllBadges ? files : files.slice(0, totalBadgesCount > 3 ? 2 : files.length)"
                  :key="file.id"
                  class="flex items-center gap-2 px-3 py-2 rounded-lg bg-black/10 dark:bg-white/10 hover:bg-black/20 dark:hover:bg-white/20 transition-colors cursor-pointer text-sm"
                  @click="downloadFile(file)"
                >
                  <Icon :icon="getFileIcon(file.fileType)" class="w-4 h-4 flex-shrink-0" />
                  <span class="font-medium truncate max-w-[200px]">{{ file.filename }}</span>
                  <span v-if="file.fileSize" class="text-xs opacity-60">{{ formatFileSize(file.fileSize) }}</span>
                </div>
              </template>

              <!-- Web Search Badge -->
              <div v-if="webSearch" class="flex items-center gap-2 px-3 py-2 rounded-lg bg-[var(--brand-alpha-light)] text-[var(--brand)] text-sm">
                <Icon icon="mdi:web" class="w-4 h-4 flex-shrink-0" />
                <span class="font-medium">Web Search</span>
                <span v-if="webSearch.query && showAllBadges" class="text-xs opacity-80 hidden sm:inline truncate max-w-[150px]">· {{ webSearch.query }}</span>
                <span v-if="webSearch.resultsCount" class="text-xs opacity-80 font-semibold">
                  · {{ webSearch.resultsCount }}
                </span>
              </div>

              <!-- Show More/Less Button -->
              <button
                v-if="totalBadgesCount > 3"
                @click="showAllBadges = !showAllBadges"
                class="flex items-center gap-1 px-3 py-2 rounded-lg bg-black/5 dark:bg-white/5 hover:bg-black/10 dark:hover:bg-white/10 transition-colors text-sm txt-secondary font-medium"
                data-testid="btn-message-badges-toggle"
              >
                <span v-if="!showAllBadges">+{{ totalBadgesCount - (webSearch ? 3 : 2) }}</span>
                <Icon :icon="showAllBadges ? 'mdi:chevron-up' : 'mdi:chevron-down'" class="w-4 h-4" />
              </button>
            </div>
          </div>
          
          <!-- Message Content -->
          <MessagePart
            v-for="(part, index) in contentParts"
            :key="index"
            :part="part"
          />

          <!-- Web Search Results Carousel (AFTER content) -->
          <div v-if="searchResults && searchResults.length > 0 && role === 'assistant'" class="mt-4 pt-3 border-t border-light-border/20 dark:border-dark-border/20 space-y-3">
            <!-- Header with Expand/Collapse Button -->
            <div class="flex items-center justify-between gap-2">
              <button
                @click="sourcesExpanded = !sourcesExpanded"
                class="flex items-center gap-2 text-sm font-medium txt-tertiary hover:txt-primary transition-colors"
                data-testid="btn-message-sources-toggle"
              >
                <Icon icon="mdi:web" class="w-4 h-4" />
                <span class="hidden sm:inline">{{ $t('search.sources') }}</span>
                <span class="text-xs txt-muted">({{ searchResults.length }})</span>
                <Icon 
                  :icon="sourcesExpanded ? 'mdi:chevron-up' : 'mdi:chevron-down'" 
                  class="w-4 h-4 transition-transform"
                />
              </button>
              
              <!-- Carousel Navigation (only when expanded) -->
              <div 
                v-if="sourcesExpanded && ((searchResults.length > 1) || (searchResults.length > 3))" 
                class="flex items-center gap-1"
              >
                <button
                  @click="previousSource"
                  :disabled="carouselPage === 0"
                  class="p-1 sm:p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="'Previous'"
                  data-testid="btn-message-sources-prev"
                >
                  <Icon icon="mdi:chevron-left" class="w-4 h-4 sm:w-5 sm:h-5" />
                </button>
                <span class="text-xs txt-muted min-w-[2.5rem] sm:min-w-[3rem] text-center">
                  <span class="hidden sm:inline">{{ carouselPage * 3 + 1 }}-{{ Math.min((carouselPage + 1) * 3, searchResults.length) }} / </span>
                  <span class="sm:hidden">{{ carouselPage + 1 }} / {{ Math.ceil(searchResults.length / 3) }}</span>
                  <span class="hidden sm:inline">{{ searchResults.length }}</span>
                </span>
                <button
                  @click="nextSource"
                  :disabled="carouselPage >= Math.ceil(searchResults.length / 3) - 1"
                  class="p-1 sm:p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="'Next'"
                  data-testid="btn-message-sources-next"
                >
                  <Icon icon="mdi:chevron-right" class="w-4 h-4 sm:w-5 sm:h-5" />
                </button>
              </div>
            </div>
            
            <!-- Carousel Container (collapsible) -->
            <div v-show="sourcesExpanded" class="py-2 px-3">
              <div class="relative overflow-x-hidden">
                <div 
                  class="flex gap-2 transition-transform duration-300"
                  :style="{ 
                    transform: `translateX(calc(-${carouselPage * 100}%))` 
                  }"
                >
                  <div
                    v-for="(result, index) in searchResults"
                    :key="index"
                    :ref="el => sourceRefs[index] = el"
                    :class="[
                      'group flex flex-col gap-2 p-2 sm:p-3 rounded-lg transition-all cursor-pointer flex-shrink-0',
                      'w-full sm:w-[calc(33.333%-0.5rem)]',
                      'bg-[var(--bg-chip)] border shadow-sm',
                      highlightedSource === index
                        ? '!border-[var(--brand)] border-2 bg-[var(--brand-alpha-light)] shadow-lg'
                        : 'border-[var(--border-light)]'
                    ]"
                    @click="focusSource(index)"
                  >
                  <!-- Header: Badge + Source Name + Open Button (Mobile) -->
                  <div class="flex items-center gap-2">
                    <!-- Badge Number (clickable) -->
                    <button
                      @click.stop="focusSource(index)"
                      :class="[
                        'inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold flex-shrink-0 transition-all',
                        'hover:scale-110 active:scale-95',
                        highlightedSource === index
                          ? 'bg-[var(--brand)] text-white shadow-md'
                          : 'bg-[var(--brand-alpha-light)] text-[var(--brand)] hover:bg-[var(--brand)] hover:text-white'
                      ]"
                      :title="`Highlight source ${index + 1}`"
                    >
                      {{ index + 1 }}
                    </button>
                    
                    <!-- Source Name -->
                    <span class="text-xs txt-muted truncate flex-1">{{ result.source }}</span>
                    
                    <!-- Open Link Button (visible when highlighted) -->
                    <button
                      v-if="highlightedSource === index"
                      @click.stop="openSource(result.url)"
                      class="flex items-center gap-1 px-2 py-1 rounded-md bg-[var(--brand)] text-white text-xs font-medium hover:opacity-90 transition-opacity"
                      title="Open link"
                    >
                      <Icon icon="mdi:open-in-new" class="w-3.5 h-3.5" />
                      <span class="hidden sm:inline">Open</span>
                    </button>
                  </div>
                  
                  <!-- Thumbnail (clickable to open) -->
                  <div
                    v-if="result.thumbnail"
                    @click.stop="openSource(result.url)"
                    class="w-full aspect-video rounded-lg overflow-hidden bg-black/5 dark:bg-white/5 hover:opacity-90 transition-opacity cursor-pointer"
                  >
                    <img
                      :src="result.thumbnail"
                      :alt="result.title"
                      class="w-full h-full object-cover"
                      loading="lazy"
                      @error="handleThumbnailError"
                    />
                  </div>
                  
                  <!-- Content -->
                  <div class="flex-1 min-w-0 space-y-1">
                    <!-- Title (clickable to open) -->
                    <div
                      @click.stop="openSource(result.url)"
                      class="text-sm font-medium line-clamp-2 group-hover:text-[var(--brand)] transition-colors hover:underline cursor-pointer"
                    >
                      {{ result.title }}
                    </div>
                    
                    <div v-if="result.description" class="text-xs txt-tertiary line-clamp-2">
                      {{ result.description }}
                    </div>
                    <div v-if="result.published" class="text-xs txt-muted opacity-60">
                      {{ result.published }}
                    </div>
                  </div>
                </div>
              </div>
            </div>
            </div>
          </div>
        </div>

      <!-- Footer with separator line and responsive layout -->
      <div
        :class="[
          'px-3 md:px-4 py-2 border-t md:min-h-[46px] flex flex-col md:flex-row md:items-center justify-between gap-2 md:gap-3',
          role === 'user'
            ? 'border-white/20'
            : 'border-light-border/30 dark:border-dark-border/20'
        ]"
      >
        <!-- Left: AI Model Badges + timestamp -->
        <div class="flex items-center gap-1 min-w-0 flex-wrap">
          <!-- Topic Badge (assistant only) - Ultra compact + Clickable -->
          <template v-if="role === 'assistant' && topic">
            <router-link
              :to="`/config/task-prompts?topic=${topic}`"
              class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 hover:bg-emerald-500/20 transition-colors leading-tight cursor-pointer max-w-[9rem]"
              :title="`Topic: ${topic} - Click to view prompt`"
            >
              <Icon icon="mdi:tag" class="w-2.5 h-2.5" />
              <span class="uppercase tracking-tight truncate">{{ topic }}</span>
            </router-link>
            <span v-if="aiModels" class="text-txt-secondary/40 text-xs mx-0.5">·</span>
          </template>
          
          <!-- AI Model Badges (assistant only) -->
          <template v-if="role === 'assistant' && aiModels">
            <!-- Chat/Image/Video Model Badge (dynamic based on content type) -->
            <button
              v-if="aiModels.chat"
              type="button"
              class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium bg-brand-alpha-light hover:bg-brand-alpha transition-colors cursor-pointer"
              :title="getModelTypeTitle"
              @click="showModelDetails('chat')"
              data-testid="btn-message-model-chat"
            >
              <Icon :icon="getModelTypeIcon" class="w-3.5 h-3.5" />
              <span class="hidden sm:inline">{{ getModelTypeLabel }}:</span>
              <span class="font-semibold">{{ aiModels.chat.model }}</span>
            </button>
            
            <!-- Sorting Model Badge -->
            <button
              v-if="aiModels.sorting"
              type="button"
              class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium bg-purple-500/10 hover:bg-purple-500/20 text-purple-600 dark:text-purple-400 transition-colors cursor-pointer"
              :title="$t('config.aiModels.messageClassification')"
              @click="showModelDetails('sorting')"
              data-testid="btn-message-model-sorting"
            >
              <Icon icon="mdi:sort" class="w-3.5 h-3.5" />
              <span class="hidden sm:inline">{{ $t('config.aiModels.sorting') }}:</span>
              <span class="font-semibold">{{ aiModels.sorting.model }}</span>
            </button>
            
            <span v-if="aiModels.chat || aiModels.sorting" class="mx-1 opacity-50 hidden md:inline">·</span>
          </template>
          
          <div :class="['text-xs truncate', role === 'user' ? 'text-white/80' : 'txt-secondary']">
            <template v-if="role === 'assistant' && modelLabel && provider && !aiModels">
              <span class="font-medium hidden md:inline">{{ modelLabel }}</span>
              <span class="mx-1.5 opacity-50 hidden md:inline">·</span>
              <span class="hidden md:inline">{{ provider }}</span>
              <span class="mx-1.5 opacity-50 hidden md:inline">·</span>
            </template>
            <span>{{ formattedTime }}</span>
            <template v-if="role === 'assistant' && modelLabel && !aiModels">
              <span class="mx-1.5 opacity-50 md:hidden">·</span>
              <span class="md:hidden">{{ modelLabel }}</span>
            </template>
          </div>
        </div>

        <!-- Right: Actions (assistant only, hidden during streaming) -->
        <!-- Show if: has againData OR has backend message ID (can fetch models) -->
        <div v-if="role === 'assistant' && !isStreaming && backendMessageId" class="flex items-center gap-2 flex-shrink-0">
          <button
            @click="handleAgain"
            type="button"
            :disabled="isSuperseded || !selectedModel || !hasModels"
            :class="[
              'pill text-xs whitespace-nowrap',
              (isSuperseded || !selectedModel || !hasModels) ? 'opacity-50 cursor-not-allowed' : ''
            ]"
            :aria-label="$t('chatMessage.again')"
            data-testid="btn-message-again"
          >
            <ArrowPathIcon class="w-4 h-4" />
            <span v-if="selectedModel" class="font-medium hidden sm:inline">{{ $t('chatMessage.againWith') }} {{ selectedModel.label }}</span>
            <span v-else class="font-medium hidden sm:inline">{{ $t('chatMessage.again') }}</span>
            <span class="font-medium sm:hidden">{{ $t('chatMessage.again') }}</span>
          </button>

          <div class="relative">
            <button
              @click.stop="toggleModelDropdown"
              type="button"
              :disabled="isSuperseded"
              :class="[
                'pill text-xs',
                isSuperseded ? 'opacity-50 cursor-not-allowed' : ''
              ]"
              :aria-label="$t('chatMessage.regenerateWith')"
              @keydown.escape="closeModelDropdown"
              data-testid="btn-message-model-toggle"
            >
              <ChevronDownIcon class="w-4 h-4" />
            </button>

            <Transition
              enter-active-class="transition ease-out duration-100"
              enter-from-class="transform opacity-0 scale-95"
              enter-to-class="transform opacity-100 scale-100"
              leave-active-class="transition ease-in duration-75"
              leave-from-class="transform opacity-100 scale-100"
              leave-to-class="transform opacity-0 scale-95"
            >
              <div
                v-if="modelDropdownOpen && !isSuperseded"
                v-click-outside="closeModelDropdown"
                class="fixed sm:absolute bottom-[60px] sm:bottom-full right-2 sm:right-0 sm:mb-2 min-w-[14rem] max-w-[calc(100vw-1rem)] dropdown-panel z-[100] max-h-[16rem] overflow-y-auto scroll-thin"
                @keydown.escape="closeModelDropdown"
              >
                <button
                  v-for="option in modelOptions"
                  :key="`${option.provider}-${option.model}`"
                  @click="selectModel(option)"
                  type="button"
                  :class="[
                    'dropdown-item',
                    selectedModel && selectedModel.model === option.model && selectedModel.provider === option.provider
                      ? 'dropdown-item--active'
                      : ''
                  ]"
                >
                  <GroqIcon 
                    v-if="option.provider.toLowerCase().includes('groq')"
                    :size="20" 
                    class-name="flex-shrink-0" 
                  />
                  <Icon 
                    v-else
                    :icon="getProviderIcon(option.provider)" 
                    class="w-5 h-5 flex-shrink-0" 
                  />
                  <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium">{{ option.label }}</div>
                    <div class="text-xs txt-secondary">{{ option.provider }}</div>
                  </div>
                  <span
                    v-if="selectedModel && selectedModel.model === option.model && selectedModel.provider === option.provider"
                    class="ml-2 text-[10px] uppercase tracking-wide text-brand font-semibold"
                  >
                    Aktiv
                  </span>
                </button>
              </div>
            </Transition>
          </div>
        </div>
      </div>
      </div>
    </div>

    <!-- Avatar on right for user -->
    <div
      v-if="role === 'user'"
      class="w-10 h-10 rounded-full surface-chip flex items-center justify-center flex-shrink-0"
    >
      <UserIcon class="w-5 h-5 txt-secondary" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { UserIcon, ArrowPathIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useModelSelection, type ModelOption } from '@/composables/useModelSelection'
import { getProviderIcon } from '@/utils/providerIcons'
import MessagePart from './MessagePart.vue'
import GroqIcon from '@/components/icons/GroqIcon.vue'
import type { Part, MessageFile } from '@/stores/history'
import type { AgainData } from '@/types/ai-models'

interface Props {
  role: 'user' | 'assistant'
  parts: Part[]
  timestamp: Date
  isSuperseded?: boolean
  isStreaming?: boolean
  provider?: string
  modelLabel?: string
  topic?: string // Topic from message classification
  againData?: AgainData
  backendMessageId?: number
  processingStatus?: string
  processingMetadata?: any
  files?: MessageFile[] // Attached files
  searchResults?: Array<{
    title: string
    url: string
    description?: string
    published?: string
    source?: string
    thumbnail?: string
  }> | null // Web search results
  aiModels?: {
    chat?: {
      provider: string
      model: string
      model_id: number | null
    }
    sorting?: {
      provider: string
      model: string
      model_id: number | null
    }
  } | null // AI model metadata
  webSearch?: {
    enabled?: boolean
    query?: string
    resultsCount?: number
  } | null // Web search metadata
}

const props = defineProps<Props>()

// Badge collapse state
const showAllBadges = ref(false)

// Sources expand/collapse state
const sourcesExpanded = ref(false)

// Carousel state for search results
const carouselPage = ref(0) // Which "page" we're on (0-based)
const highlightedSource = ref<number | null>(null)
const sourceRefs = ref<any[]>([])

// Calculate total badges count (files + webSearch)
const totalBadgesCount = computed(() => {
  let count = 0
  if (props.files) count += props.files.length
  if (props.webSearch) count += 1
  return count
})

// Carousel navigation
const nextSource = () => {
  if (props.searchResults) {
    const maxPage = Math.ceil(props.searchResults.length / 3) - 1
    if (carouselPage.value < maxPage) {
      carouselPage.value += 1
    }
  }
}

const previousSource = () => {
  if (carouselPage.value > 0) {
    carouselPage.value -= 1
  }
}

// Focus and highlight a source (without opening URL)
const focusSource = (index: number) => {
  highlightedSource.value = index
  
  // Expand sources if collapsed
  if (!sourcesExpanded.value) {
    sourcesExpanded.value = true
  }
  
  // Navigate to carousel page containing this source
  if (props.searchResults) {
    // Calculate which "page" this source is on (groups of 3 on desktop)
    const page = Math.floor(index / 3)
    carouselPage.value = page
  }
}

// Open source URL (separate action)
const openSource = (url: string) => {
  window.open(url, '_blank', 'noopener,noreferrer')
}

// Separate thinking blocks from content
const thinkingParts = computed(() => props.parts.filter(p => p.type === 'thinking'))

// Process content parts to make reference numbers [1], [2], etc. clickable
const contentParts = computed(() => {
  const parts = props.parts.filter(p => p.type !== 'thinking')
  
  // If no search results, return parts as-is
  if (!props.searchResults || props.searchResults.length === 0) {
    return parts
  }
  
  // Process text parts to add clickable references
  return parts.map(part => {
    if (part.type === 'text' && part.content) {
      // Replace [1], [2], etc. with clickable spans
      const processedContent = part.content.replace(
        /\[(\d+)\]/g,
        (match, num) => {
          const index = parseInt(num) - 1
          if (index >= 0 && index < props.searchResults!.length) {
            return `<a href="#" class="source-ref inline-flex items-center justify-center w-5 h-5 rounded-full bg-[var(--brand-alpha-light)] text-[var(--brand)] text-xs font-bold hover:bg-[var(--brand)] hover:text-white transition-all mx-0.5 no-underline" data-source-index="${index}" onclick="event.preventDefault()">${num}</a>`
          }
          return match
        }
      )
      
      return {
        ...part,
        content: processedContent
      }
    }
    return part
  })
})

// Get provider for avatar icon (prefer aiModels.chat, fallback to legacy provider prop)
const displayProvider = computed(() => {
  if (props.aiModels?.chat?.provider) {
    return props.aiModels.chat.provider
  }
  return props.provider || 'OpenAI'
})

// Determine model type based on message content
const hasImageContent = computed(() => props.parts.some(p => p.type === 'image'))
const hasVideoContent = computed(() => props.parts.some(p => p.type === 'video'))
const hasAudioContent = computed(() => props.parts.some(p => p.type === 'audio'))

const mediaHint = computed(() => {
  if (hasImageContent.value) return 'image' as const
  if (hasVideoContent.value) return 'video' as const
  if (hasAudioContent.value) return 'audio' as const
  return null
})

// Dynamic label for model badge based on content type
const getModelTypeLabel = computed(() => {
  if (hasImageContent.value) return 'Image Model'
  if (hasVideoContent.value) return 'Video Model'
  if (hasAudioContent.value) return 'Audio Model'
  return 'Chat Model'
})

// Dynamic icon for model badge
const getModelTypeIcon = computed(() => {
  if (hasImageContent.value) return 'mdi:image'
  if (hasVideoContent.value) return 'mdi:video'
  if (hasAudioContent.value) return 'mdi:music'
  return 'mdi:chat'
})

// Dynamic title for model badge
const getModelTypeTitle = computed(() => {
  if (hasImageContent.value) return 'Image Generation (Text → Image)'
  if (hasVideoContent.value) return 'Video Generation (Text → Video)'
  if (hasAudioContent.value) return 'Audio Generation (Text → Audio)'
  return 'Chat Generation'
})

const formattedTime = computed(() => {
  const date = props.timestamp
  const hours = date.getHours().toString().padStart(2, '0')
  const minutes = date.getMinutes().toString().padStart(2, '0')
  return `${hours}:${minutes}`
})

const emit = defineEmits<{
  regenerate: [model: ModelOption]
  again: [backendMessageId: number, modelId?: number]
}>()

const router = useRouter()
const modelDropdownOpen = ref(false)

// Use model selection composable
const againDataComputed = computed(() => props.againData)
const filesComputed = computed(() => props.files)
const currentProviderComputed = computed(() => props.provider)
const currentModelNameComputed = computed(() => props.modelLabel)
const { modelOptions, predictedModel, hasModels } = useModelSelection(
  againDataComputed, 
  filesComputed,
  currentProviderComputed,
  currentModelNameComputed,
  mediaHint
)

// Selected model: use predicted or first available
const selectedModel = computed(() => predictedModel.value)

// Navigate to AI models configuration with highlight
const showModelDetails = (modelType?: 'chat' | 'sorting') => {
  if (modelType === 'chat') {
    // Determine the correct capability based on content type
    let capability = 'CHAT'
    
    if (hasImageContent.value) {
      capability = 'TEXT2PIC'
    } else if (hasVideoContent.value) {
      capability = 'TEXT2VID'
    } else if (hasAudioContent.value) {
      capability = 'TEXT2SOUND'
    }
    
    router.push({ path: '/config/ai-models', query: { highlight: capability } })
  } else if (modelType === 'sorting') {
    router.push({ path: '/config/ai-models', query: { highlight: 'SORT' } })
  } else {
    router.push('/config/ai-models')
  }
}

const handleAgain = () => {
  const model = selectedModel.value
  if (!model) {
    return
  }

  if (props.backendMessageId && (model as any).id) {
    // New backend-driven again
    emit('again', props.backendMessageId, (model as any).id)
  } else {
    // Fallback to old regenerate
    emit('regenerate', model as any)
  }
}

const toggleModelDropdown = () => {
  modelDropdownOpen.value = !modelDropdownOpen.value
}

const closeModelDropdown = () => {
  modelDropdownOpen.value = false
}

const selectModel = (model: ModelOption & { id?: number }) => {
  // Trigger again with selected model
  if (props.backendMessageId && model.id) {
    emit('again', props.backendMessageId, model.id)
  } else {
    emit('regenerate', model)
  }
  modelDropdownOpen.value = false
}

const vClickOutside = {
  mounted(el: HTMLElement, binding: any) {
    const handler = (event: MouseEvent) => {
      if (!(el === event.target || el.contains(event.target as Node))) {
        binding.value()
      }
    }
    (el as any).__clickOutsideHandler = handler
    setTimeout(() => {
      document.addEventListener('click', handler)
    }, 0)
  },
  unmounted(el: HTMLElement) {
    const handler = (el as any).__clickOutsideHandler
    if (handler) {
      document.removeEventListener('click', handler)
    }
  },
}

// File handling functions
const getFileIcon = (fileType: string): string => {
  const type = fileType.toLowerCase()
  if (['pdf'].includes(type)) return 'mdi:file-pdf-box'
  if (['doc', 'docx'].includes(type)) return 'mdi:file-word-box'
  if (['xls', 'xlsx'].includes(type)) return 'mdi:file-excel-box'
  if (['ppt', 'pptx'].includes(type)) return 'mdi:file-powerpoint-box'
  if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(type)) return 'mdi:file-image'
  if (['mp3', 'wav', 'ogg', 'm4a', 'opus'].includes(type)) return 'mdi:file-music'
  if (['mp4', 'avi', 'mov', 'webm'].includes(type)) return 'mdi:file-video'
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(type)) return 'mdi:folder-zip'
  if (['txt', 'md'].includes(type)) return 'mdi:file-document-outline'
  return 'mdi:file-outline'
}

const formatFileSize = (bytes: number): string => {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
  return (bytes / (1024 * 1024 * 1024)).toFixed(1) + ' GB'
}

const downloadFile = async (file: MessageFile) => {
  try {
    const filesService = await import('@/services/filesService')
    await filesService.downloadFile(file.id, file.filename)
  } catch (error) {
    console.error('Download failed:', error)
  }
}

// Handle clicks on reference numbers in the text
const handleReferenceClick = (event: MouseEvent) => {
  const target = event.target as HTMLElement
  if (target.classList.contains('source-ref')) {
    const index = parseInt(target.dataset.sourceIndex || '-1')
    if (index >= 0 && props.searchResults && index < props.searchResults.length) {
      focusSource(index)
    }
  }
}

// Handle thumbnail loading errors silently by replacing with placeholder
const handleThumbnailError = (event: Event) => {
  const img = event.target as HTMLImageElement
  if (img) {
    // Replace with a data URL placeholder to avoid console spam
    img.src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200"%3E%3Crect width="200" height="200" fill="%23f3f4f6"/%3E%3Cpath d="M70 80h60v40H70z" fill="%23d1d5db"/%3E%3Ccircle cx="85" cy="95" r="8" fill="%23ffffff"/%3E%3Cpath d="M70 110l20-15 15 10 25-20v35H70z" fill="%239ca3af"/%3E%3C/svg%3E'
    img.onerror = null // Prevent infinite loop
  }
}

// Add event listener for reference clicks
onMounted(() => {
  document.addEventListener('click', handleReferenceClick)
})

onUnmounted(() => {
  document.removeEventListener('click', handleReferenceClick)
})

</script>
