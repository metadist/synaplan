<template>
  <div
    :class="[
      'flex gap-4 p-4 text-[16px] leading-6',
      role === 'user' ? 'justify-end' : '',
      isSuperseded && 'opacity-50',
    ]"
    data-testid="message-container"
  >
    <!-- Avatar with provider logo for assistant -->
    <div
      v-if="role === 'assistant'"
      class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 surface-card"
    >
      <GroqIcon v-if="displayProvider.toLowerCase().includes('groq')" :size="24" class-name="" />
      <Icon v-else :icon="getProviderIcon(displayProvider)" class="w-6 h-6" />
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
          <!-- Trennlinie für Memory-Processing (nach dem Haupt-Content) -->
          <div
            v-if="
              processingStatus.startsWith('analyzing_memories') ||
              processingStatus.startsWith('saving_memories') ||
              processingStatus.startsWith('memories_complete')
            "
            class="border-t border-gray-200 dark:border-gray-700 mb-3 -mx-4"
          ></div>

          <div class="flex items-center gap-3">
            <!-- Icon: Brain for memory-related, otherwise spinner -->
            <svg
              v-if="processingStatus.includes('memories')"
              class="w-5 h-5 txt-brand flex-shrink-0"
              :class="{ 'animate-pulse': processingStatus === 'analyzing_memories' }"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"
              />
            </svg>
            <svg
              v-else
              class="w-5 h-5 animate-spin txt-brand flex-shrink-0"
              fill="none"
              viewBox="0 0 24 24"
            >
              <circle
                class="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                stroke-width="4"
              />
              <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
              />
            </svg>
            <div class="flex-1 min-w-0">
              <template v-if="processingStatus === 'started'">
                <div class="font-medium">{{ $t('processing.startedTitle') }}</div>
                <div class="text-sm txt-tertiary mt-0.5">{{ $t('processing.startedDesc') }}</div>
              </template>
              <template v-else-if="processingStatus === 'preprocessing'">
                <div class="font-medium">{{ $t('processing.preprocessingTitle') }}</div>
                <div class="text-sm txt-tertiary mt-0.5">
                  {{ $t('processing.preprocessingDesc') }}
                </div>
              </template>
              <template v-else-if="processingStatus === 'classifying'">
                <div class="font-medium animate-pulse">{{ $t('processing.classifyingTitle') }}</div>
                <div class="text-sm txt-tertiary mt-0.5">
                  {{ $t('processing.classifyingDesc') }}
                  <span
                    v-if="processingMetadata?.model_name || processingMetadata?.provider"
                    class="txt-brand"
                  >
                    · {{ processingMetadata.model_name || processingMetadata.provider }}
                  </span>
                </div>
              </template>
              <template v-else-if="processingStatus === 'classified'">
                <div class="font-medium">{{ $t('processing.classifiedTitle') }}</div>
                <div class="text-sm txt-tertiary mt-0.5 flex items-center gap-1.5 flex-wrap">
                  <span>{{ $t('processing.topic') }}:</span>
                  <span class="txt-brand font-medium">{{
                    processingMetadata?.topic || 'general'
                  }}</span>
                  <span v-if="processingMetadata?.language" class="opacity-50">·</span>
                  <span v-if="processingMetadata?.language">
                    {{ $t('processing.language') }}:
                    <span class="font-medium">{{ processingMetadata.language.toUpperCase() }}</span>
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
                    <span
                      v-if="processingMetadata?.model_name || processingMetadata?.provider"
                      class="txt-brand"
                    >
                      · {{ processingMetadata.model_name || processingMetadata.provider }}
                    </span>
                  </template>
                </div>
              </template>
              <template v-else-if="processingStatus === 'generating_file'">
                <div class="font-medium animate-pulse">
                  {{ $t('processing.generatingFileTitle') }}
                </div>
                <div class="text-sm txt-tertiary mt-0.5">
                  {{ processingMetadata?.customMessage || $t('processing.generatingFileDesc') }}
                </div>
              </template>
              <template v-else-if="processingStatus === 'analyzing_memories'">
                <div class="font-medium animate-pulse">
                  {{ $t('processing.analyzingMemoriesTitle') }}
                </div>
                <div class="text-sm txt-tertiary mt-0.5">
                  {{ processingMetadata?.customMessage || $t('processing.analyzingMemoriesDesc') }}
                </div>
              </template>
              <template v-else-if="processingStatus === 'saving_memories'">
                <div class="font-medium">{{ $t('processing.savingMemoriesTitle') }}</div>
                <div class="text-sm txt-tertiary mt-0.5">
                  {{ processingMetadata?.customMessage || $t('processing.savingMemoriesDesc') }}
                </div>
              </template>
              <template v-else-if="processingStatus === 'memories_complete'">
                <div class="font-medium">{{ $t('processing.memoriesCompleteTitle') }}</div>
                <div class="text-sm txt-tertiary mt-0.5">
                  {{ processingMetadata?.customMessage || $t('processing.memoriesCompleteDesc') }}
                </div>
              </template>
            </div>
          </div>
        </div>

        <!-- Bubble content (only non-thinking parts) -->
        <div class="px-4 py-3 overflow-hidden space-y-3">
          <!-- Combined Badges: Files + Web Search + Tool (NEW) -->
          <div v-if="(files && files.length > 0) || webSearch || tool" class="space-y-2">
            <!-- Show badges with smart collapsing -->
            <div class="flex flex-wrap gap-2">
              <!-- Files (show based on collapse state) -->
              <template v-if="files && files.length > 0">
                <div
                  v-for="file in showAllBadges
                    ? files
                    : files.slice(0, totalBadgesCount > 3 ? 2 : files.length)"
                  :key="file.id"
                  class="flex items-center gap-2 px-3 py-2 rounded-lg bg-black/10 dark:bg-white/10 hover:bg-black/20 dark:hover:bg-white/20 transition-colors cursor-pointer text-sm"
                  @click="downloadFile(file)"
                >
                  <Icon :icon="getFileIcon(file.fileType)" class="w-4 h-4 flex-shrink-0" />
                  <span class="font-medium truncate max-w-[200px]">{{ file.filename }}</span>
                  <span v-if="file.fileSize" class="text-xs opacity-60">{{
                    formatFileSize(file.fileSize)
                  }}</span>
                </div>
              </template>

              <!-- Tool Badge (replaces Web Search Badge for better consistency) -->
              <div
                v-if="tool"
                class="flex items-center gap-2 px-3 py-2 rounded-lg bg-[var(--brand-alpha-light)] text-[var(--brand)] text-sm"
              >
                <Icon :icon="tool.icon" class="w-4 h-4 flex-shrink-0" />
                <span class="font-medium">{{ tool.label }}</span>
              </div>

              <!-- Web Search Badge (fallback for legacy messages without tool metadata) -->
              <div
                v-else-if="webSearch"
                class="flex items-center gap-2 px-3 py-2 rounded-lg bg-[var(--brand-alpha-light)] text-[var(--brand)] text-sm"
              >
                <Icon icon="mdi:web" class="w-4 h-4 flex-shrink-0" />
                <span class="font-medium">Web Search</span>
                <span
                  v-if="webSearch.query && showAllBadges"
                  class="text-xs opacity-80 hidden sm:inline truncate max-w-[150px]"
                  >· {{ webSearch.query }}</span
                >
                <span v-if="webSearch.resultsCount" class="text-xs opacity-80 font-semibold">
                  · {{ webSearch.resultsCount }}
                </span>
              </div>

              <!-- Show More/Less Button -->
              <button
                v-if="totalBadgesCount > 3"
                class="flex items-center gap-1 px-3 py-2 rounded-lg bg-black/5 dark:bg-white/5 hover:bg-black/10 dark:hover:bg-white/10 transition-colors text-sm txt-secondary font-medium"
                data-testid="btn-message-badges-toggle"
                @click="showAllBadges = !showAllBadges"
              >
                <span v-if="!showAllBadges"
                  >+{{ totalBadgesCount - (tool || webSearch ? 3 : 2) }}</span
                >
                <Icon
                  :icon="showAllBadges ? 'mdi:chevron-up' : 'mdi:chevron-down'"
                  class="w-4 h-4"
                />
              </button>
            </div>
          </div>

          <!-- Message Content -->
          <MessagePart
            v-for="(part, index) in contentParts"
            :key="index"
            :part="part"
            :is-streaming="isStreaming"
            :memories="memories"
          />

          <!-- Used Memories (AFTER content, before search results) -->
          <MessageMemories
            v-if="role === 'assistant' && memories"
            :memories="memories"
            @click-memory="(memory) => emit('click-memory', memory)"
          />

          <!-- Used Feedbacks (AFTER memories, before search results) -->
          <MessageFeedbacks v-if="role === 'assistant' && feedbacks" :feedbacks="feedbacks" />

          <!-- Web Search Results Carousel (AFTER content) -->
          <div
            v-if="searchResults && searchResults.length > 0 && role === 'assistant'"
            class="mt-4 pt-3 border-t border-light-border/20 dark:border-dark-border/20 space-y-3"
          >
            <!-- Header with Expand/Collapse Button -->
            <div class="flex items-center justify-between gap-2">
              <button
                class="flex items-center gap-2 text-sm font-medium txt-tertiary hover:txt-primary transition-colors"
                data-testid="btn-message-sources-toggle"
                @click="sourcesExpanded = !sourcesExpanded"
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
                v-if="sourcesExpanded && (searchResults.length > 1 || searchResults.length > 3)"
                class="flex items-center gap-1"
              >
                <button
                  :disabled="carouselPage === 0"
                  class="p-1 sm:p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="'Previous'"
                  data-testid="btn-message-sources-prev"
                  @click="previousSource"
                >
                  <Icon icon="mdi:chevron-left" class="w-4 h-4 sm:w-5 sm:h-5" />
                </button>
                <span class="text-xs txt-muted min-w-[2.5rem] sm:min-w-[3rem] text-center">
                  <span class="hidden sm:inline"
                    >{{ carouselPage * 3 + 1 }}-{{
                      Math.min((carouselPage + 1) * 3, searchResults.length)
                    }}
                    /
                  </span>
                  <span class="sm:hidden"
                    >{{ carouselPage + 1 }} / {{ Math.ceil(searchResults.length / 3) }}</span
                  >
                  <span class="hidden sm:inline">{{ searchResults.length }}</span>
                </span>
                <button
                  :disabled="carouselPage >= Math.ceil(searchResults.length / 3) - 1"
                  class="p-1 sm:p-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                  :title="'Next'"
                  data-testid="btn-message-sources-next"
                  @click="nextSource"
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
                    transform: `translateX(calc(-${carouselPage * 100}%))`,
                  }"
                >
                  <div
                    v-for="(result, index) in searchResults"
                    :key="index"
                    :class="[
                      'group flex flex-col gap-2 p-2 sm:p-3 rounded-lg transition-all cursor-pointer flex-shrink-0',
                      'w-full sm:w-[calc(33.333%-0.5rem)]',
                      'bg-[var(--bg-chip)] border shadow-sm',
                      highlightedSource === index
                        ? '!border-[var(--brand)] border-2 bg-[var(--brand-alpha-light)] shadow-lg'
                        : 'border-[var(--border-light)]',
                    ]"
                    @click="focusSource(index)"
                  >
                    <!-- Header: Badge + Source Name + Open Button (Mobile) -->
                    <div class="flex items-center gap-2">
                      <!-- Badge Number (clickable) -->
                      <button
                        :class="[
                          'inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold flex-shrink-0 transition-all',
                          'hover:scale-110 active:scale-95',
                          highlightedSource === index
                            ? 'bg-[var(--brand)] text-white shadow-md'
                            : 'bg-[var(--brand-alpha-light)] text-[var(--brand)] hover:bg-[var(--brand)] hover:text-white',
                        ]"
                        :title="`Highlight source ${index + 1}`"
                        @click.stop="focusSource(index)"
                      >
                        {{ index + 1 }}
                      </button>

                      <!-- Source Name -->
                      <span class="text-xs txt-muted truncate flex-1">{{ result.source }}</span>

                      <!-- Open Link Button (visible when highlighted) -->
                      <button
                        v-if="highlightedSource === index"
                        class="flex items-center gap-1 px-2 py-1 rounded-md bg-[var(--brand)] text-white text-xs font-medium hover:opacity-90 transition-opacity"
                        title="Open link"
                        @click.stop="openSource(result.url)"
                      >
                        <Icon icon="mdi:open-in-new" class="w-3.5 h-3.5" />
                        <span class="hidden sm:inline">Open</span>
                      </button>
                    </div>

                    <!-- Thumbnail (clickable to open) -->
                    <div
                      v-if="result.thumbnail"
                      class="w-full aspect-video rounded-lg overflow-hidden bg-black/5 dark:bg-white/5 hover:opacity-90 transition-opacity cursor-pointer"
                      @click.stop="openSource(result.url)"
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
                        class="text-sm font-medium line-clamp-2 group-hover:text-[var(--brand)] transition-colors hover:underline cursor-pointer"
                        @click.stop="openSource(result.url)"
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
              : 'border-light-border/30 dark:border-dark-border/20',
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
                data-testid="btn-message-model-chat"
                @click="showModelDetails('chat')"
              >
                <Icon :icon="getModelTypeIcon" class="w-3.5 h-3.5" />
                <span class="hidden sm:inline">{{ getModelTypeLabel }}:</span>
                <span class="font-semibold">{{ shortenModel(aiModels.chat.model) }}</span>
              </button>

              <!-- Sorting Model Badge -->
              <button
                v-if="aiModels.sorting"
                type="button"
                class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs font-medium bg-purple-500/10 hover:bg-purple-500/20 text-purple-600 dark:text-purple-400 transition-colors cursor-pointer"
                :title="`${$t('config.aiModels.messageClassification')}: ${aiModels.sorting.model}`"
                data-testid="btn-message-model-sorting"
                @click="showModelDetails('sorting')"
              >
                <Icon icon="mdi:sort" class="w-3.5 h-3.5" />
                <span class="hidden sm:inline">{{ $t('config.aiModels.sorting') }}:</span>
                <span class="font-semibold">{{ shortenModel(aiModels.sorting.model) }}</span>
              </button>

              <span
                v-if="aiModels.chat || aiModels.sorting"
                class="mx-1 opacity-50 hidden md:inline"
                >·</span
              >
            </template>

            <div :class="['text-xs truncate', role === 'user' ? 'text-white/80' : 'txt-secondary']">
              <!-- Hide model info during processing states (classifying, analyzing, etc.) -->
              <template
                v-if="role === 'assistant' && modelLabel && provider && !aiModels && !isProcessing"
              >
                <span class="font-medium hidden md:inline">{{ modelLabel }}</span>
                <span class="mx-1.5 opacity-50 hidden md:inline">·</span>
                <span class="hidden md:inline">{{ provider }}</span>
                <span class="mx-1.5 opacity-50 hidden md:inline">·</span>
              </template>
              <span>{{ formattedTime }}</span>
              <template v-if="role === 'assistant' && modelLabel && !aiModels && !isProcessing">
                <span class="mx-1.5 opacity-50 md:hidden">·</span>
                <span class="md:hidden">{{ modelLabel }}</span>
              </template>
            </div>
          </div>

          <!-- Right: Actions (assistant only, hidden during streaming) -->
          <!-- Show if: has againData OR has backend message ID (can fetch models) -->
          <div
            v-if="role === 'assistant' && !isStreaming && backendMessageId"
            class="flex items-center gap-2 flex-shrink-0"
          >
            <!-- False Positive Button - only show if memory/feedback service is available -->
            <button
              v-if="isFeedbackServiceAvailable"
              type="button"
              :disabled="isSuperseded"
              :class="['pill text-xs', isSuperseded ? 'opacity-50 cursor-not-allowed' : '']"
              :aria-label="$t('feedback.falsePositive.button')"
              data-testid="btn-message-false-positive"
              @click="handleFalsePositive"
            >
              <Icon icon="mdi:thumb-down-outline" class="w-4 h-4" />
              <span class="font-medium hidden sm:inline">{{
                $t('feedback.falsePositive.button')
              }}</span>
            </button>

            <button
              type="button"
              :disabled="isSuperseded || !selectedModel || !hasModels"
              :class="[
                'pill text-xs whitespace-nowrap',
                isSuperseded || !selectedModel || !hasModels ? 'opacity-50 cursor-not-allowed' : '',
              ]"
              :aria-label="$t('chatMessage.again')"
              data-testid="btn-message-again"
              @click="handleAgain"
            >
              <ArrowPathIcon class="w-4 h-4" />
              <span v-if="selectedModel" class="font-medium hidden sm:inline"
                >{{ $t('chatMessage.againWith') }} {{ selectedModel.label }}</span
              >
              <span v-else class="font-medium hidden sm:inline">{{ $t('chatMessage.again') }}</span>
              <span class="font-medium sm:hidden">{{ $t('chatMessage.again') }}</span>
            </button>

            <div class="relative">
              <button
                type="button"
                :disabled="isSuperseded"
                :class="['pill text-xs', isSuperseded ? 'opacity-50 cursor-not-allowed' : '']"
                :aria-label="$t('chatMessage.regenerateWith')"
                data-testid="btn-message-model-toggle"
                @click.stop="toggleModelDropdown"
                @keydown.escape="closeModelDropdown"
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
                    type="button"
                    :class="[
                      'dropdown-item',
                      selectedModel &&
                      selectedModel.model === option.model &&
                      selectedModel.provider === option.provider
                        ? 'dropdown-item--active'
                        : '',
                    ]"
                    @click="selectModel(option)"
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
                  </button>
                </div>
              </Transition>
            </div>
          </div>
        </div>

        <!-- Rate Limit Error Banner for User Messages -->
        <div
          v-if="role === 'user' && status === 'rate_limited'"
          class="mt-2 surface-card border border-amber-500/30 rounded-xl p-4 fade-in"
        >
          <div class="flex items-start gap-3">
            <div
              class="w-10 h-10 rounded-full bg-amber-500/10 flex items-center justify-center flex-shrink-0"
            >
              <Icon icon="mdi:clock-alert-outline" class="w-5 h-5 text-amber-500" />
            </div>
            <div class="flex-1 min-w-0">
              <div class="font-semibold txt-primary text-sm mb-1">
                {{ $t('limitReached.messageBlocked') }}
              </div>
              <div class="text-xs txt-secondary mb-3">
                {{
                  $t('limitReached.messageBlockedDesc', {
                    action: errorData?.actionType || 'MESSAGES',
                  })
                }}
              </div>
              <div class="flex flex-wrap gap-2">
                <button
                  class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold bg-gradient-to-r from-amber-500 to-orange-500 text-white hover:shadow-lg transition-all"
                  @click="$router.push('/subscription')"
                >
                  <Icon icon="mdi:crown" class="w-4 h-4" />
                  {{ $t('limitReached.upgradeNow') }}
                </button>
                <button
                  class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium surface-chip txt-primary hover:bg-black/5 dark:hover:bg-white/10 transition-colors"
                  @click="handleRetry"
                >
                  <Icon icon="mdi:refresh" class="w-4 h-4" />
                  {{ $t('limitReached.retryLater') }}
                </button>
              </div>
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

  <ExternalLinkWarning :url="pendingUrl" :is-open="warningOpen" @close="closeWarning" />
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { UserIcon, ArrowPathIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { useModelSelection, type ModelOption } from '@/composables/useModelSelection'
import { useNotification } from '@/composables/useNotification'
import { getProviderIcon } from '@/utils/providerIcons'
import { useMemoriesStore } from '@/stores/userMemories'
import { useFeedbackStore } from '@/stores/userFeedback'
import { useConfigStore } from '@/stores/config'
import type { UserMemory } from '@/services/api/userMemoriesApi'
import MessagePart from './MessagePart.vue'
import MessageMemories from './MessageMemories.vue'
import MessageFeedbacks from './MessageFeedbacks.vue'
import GroqIcon from '@/components/icons/GroqIcon.vue'
import ExternalLinkWarning from '@/components/common/ExternalLinkWarning.vue'
import { useExternalLink } from '@/composables/useExternalLink'
import type { Part, MessageFile } from '@/stores/history'
import type { AgainData } from '@/types/ai-models'

const { t } = useI18n()
const { error: showError } = useNotification()
const { pendingUrl, warningOpen, openExternalLink, closeWarning } = useExternalLink()

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
  processingMetadata?: {
    model_name?: string
    provider?: string
    topic?: string
    language?: string
    customMessage?: string
    results_count?: number
    handler?: string
  } | null
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
  tool?: {
    icon: string
    label: string
  } | null // Tool metadata (e.g., web search, file generation)
  memoryIds?: number[] | null // IDs of memories used (resolved from memoriesStore)
  feedbackIds?: number[] | null // IDs of feedbacks used (resolved from feedbackStore)
  // Status for failed/pending messages
  status?: 'sent' | 'failed' | 'rate_limited'
  errorType?: 'rate_limit' | 'connection' | 'unknown'
  errorData?: {
    limitType?: string
    actionType?: string
    used?: number
    limit?: number
    remaining?: number
    resetAt?: number | null
    userLevel?: string
  }
}

const props = defineProps<Props>()

// Badge collapse state
const showAllBadges = ref(false)

// Sources expand/collapse state
const sourcesExpanded = ref(false)

// Carousel state for search results
const carouselPage = ref(0) // Which "page" we're on (0-based)
const highlightedSource = ref<number | null>(null)

// Calculate total badges count (files + webSearch/tool)
const totalBadgesCount = computed(() => {
  let count = 0
  if (props.files) count += props.files.length
  if (props.tool || props.webSearch) count += 1
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

// Open source URL (with external link warning)
const openSource = (url: string) => {
  openExternalLink(url)
}

// Separate thinking blocks from content
const thinkingParts = computed(() => props.parts.filter((p) => p.type === 'thinking'))

// Resolve memoryIds to full UserMemory objects from the store
const memoriesStore = useMemoriesStore()
const feedbackStore = useFeedbackStore()
const config = useConfigStore()

// Check if feedback service (same as memory service) is available
const isFeedbackServiceAvailable = computed(() => config.features.memoryService)

const memories = computed(() => {
  if (!props.memoryIds || props.memoryIds.length === 0) {
    return null
  }
  // Resolve memory IDs to full UserMemory objects
  const resolved = props.memoryIds
    .map((id) => memoriesStore.memories.find((m) => m.id === id))
    .filter((m) => m !== undefined)

  return resolved.length > 0 ? resolved : null
})

const feedbacks = computed(() => {
  if (!props.feedbackIds || props.feedbackIds.length === 0) {
    return null
  }
  // Resolve feedback IDs to full Feedback objects
  const resolved = props.feedbackIds
    .map((id) => feedbackStore.getFeedbackById(id))
    .filter((f) => f !== undefined)

  return resolved.length > 0 ? resolved : null
})

// Process content parts to make reference numbers [1], [2], etc. clickable for search results
// NOTE: Memory badges ([Memory X]) are handled in MessageText.vue, not here!
const contentParts = computed(() => {
  const parts = props.parts.filter((p) => p.type !== 'thinking')

  // If no search results, return parts as-is
  if (!props.searchResults || props.searchResults.length === 0) {
    return parts
  }

  // Process text parts to add clickable search result references
  return parts.map((part) => {
    if (part.type === 'text' && part.content) {
      let processedContent = part.content

      // Replace [1], [2], etc. with clickable spans for search results
      processedContent = processedContent.replace(/\[(\d+)\]/g, (match, num) => {
        const index = parseInt(num) - 1
        if (index >= 0 && index < props.searchResults!.length) {
          return `<a href="#" class="source-ref inline-flex items-center justify-center w-5 h-5 rounded-full bg-[var(--brand-alpha-light)] text-[var(--brand)] text-xs font-bold hover:bg-[var(--brand)] hover:text-white transition-all mx-0.5 no-underline" data-source-index="${index}" onclick="event.preventDefault()">${num}</a>`
        }
        return match
      })

      return {
        ...part,
        content: processedContent,
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
const hasImageContent = computed(() => props.parts.some((p) => p.type === 'image'))
const hasVideoContent = computed(() => props.parts.some((p) => p.type === 'video'))
const hasAudioContent = computed(() => props.parts.some((p) => p.type === 'audio'))

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

// Check if we're in a processing state (hide model info during these states)
const isProcessing = computed(() => {
  if (!props.isStreaming || !props.processingStatus) return false
  const processingStates = [
    'started',
    'preprocessing',
    'classifying',
    'analyzing',
    'analyzing_memories',
    'saving_memories',
    'generating',
  ]
  return processingStates.some((state) => props.processingStatus?.startsWith(state))
})

const emit = defineEmits<{
  regenerate: [model: ModelOption]
  again: [backendMessageId: number, modelId?: number]
  retry: [messageContent: string]
  falsePositive: [text: string, messageId?: number]
  'click-memory': [memory: UserMemory]
}>()

const router = useRouter()
const modelDropdownOpen = ref(false)

const shortenModel = (name: string): string => {
  const stripped = name.replace(/^[^/]+\//, '')
  if (stripped.length <= 24) return stripped
  return stripped.slice(0, 22) + '…'
}

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

// Handle retry for rate-limited messages
const handleRetry = () => {
  // Extract text content from message
  const textContent = props.parts
    .filter((p) => p.type === 'text')
    .map((p) => p.content || '')
    .join('\n')

  if (textContent) {
    emit('retry', textContent)
  }
}

const handleAgain = () => {
  const model = selectedModel.value
  if (!model) {
    return
  }

  if (props.backendMessageId && model.id) {
    // New backend-driven again
    emit('again', props.backendMessageId, model.id)
  } else {
    // Fallback to old regenerate
    emit('regenerate', model)
  }
}

const handleFalsePositive = () => {
  const textContent = props.parts
    .filter((part) => part.type === 'text' && part.content)
    .map((part) => (part.content ?? '').trim())
    .filter(Boolean)
    .join('\n\n')

  if (!textContent) {
    return
  }

  emit('falsePositive', textContent, props.backendMessageId)
}

const toggleModelDropdown = () => {
  modelDropdownOpen.value = !modelDropdownOpen.value
}

const closeModelDropdown = () => {
  modelDropdownOpen.value = false
}

const selectModel = (model: ModelOption) => {
  // Trigger again with selected model
  if (props.backendMessageId && model.id) {
    emit('again', props.backendMessageId, model.id)
  } else {
    emit('regenerate', model)
  }
  modelDropdownOpen.value = false
}

interface ClickOutsideElement extends HTMLElement {
  __clickOutsideHandler?: (event: MouseEvent) => void
}

const vClickOutside = {
  mounted(el: ClickOutsideElement, binding: { value: () => void }) {
    const handler = (event: MouseEvent) => {
      if (!(el === event.target || el.contains(event.target as Node))) {
        binding.value()
      }
    }
    el.__clickOutsideHandler = handler
    setTimeout(() => {
      document.addEventListener('click', handler)
    }, 0)
  },
  unmounted(el: ClickOutsideElement) {
    const handler = el.__clickOutsideHandler
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
  if (!file.id) {
    console.error('Download failed: No file ID')
    return
  }
  try {
    const filesService = await import('@/services/filesService')
    await filesService.downloadFile(file.id, file.filename)
  } catch (error) {
    console.error('Download failed:', error)
    showError(t('files.downloadFailed'))
  }
}

// Handle clicks on reference numbers in the text
const handleReferenceClick = (event: MouseEvent) => {
  const target = event.target as HTMLElement

  // Handle source references [1], [2], etc.
  if (target.classList.contains('source-ref')) {
    const index = parseInt(target.dataset.sourceIndex || '-1')
    if (index >= 0 && props.searchResults && index < props.searchResults.length) {
      focusSource(index)
    }
  }

  // Handle memory references "Memory 1", "Memory 2", etc.
  if (target.classList.contains('memory-ref') || target.closest('.memory-ref')) {
    const memoryLink = target.classList.contains('memory-ref')
      ? target
      : target.closest('.memory-ref')
    if (memoryLink) {
      const index = parseInt((memoryLink as HTMLElement).dataset.memoryIndex || '-1')
      const resolvedMemories = memories.value
      if (index >= 0 && resolvedMemories && index < resolvedMemories.length) {
        const memory = resolvedMemories[index]
        // Emit event to open MemoriesDialog in ChatView (stay in chat!)
        emit('click-memory', memory)
      }
    }
  }
}

// Handle thumbnail loading errors silently by replacing with placeholder
const handleThumbnailError = (event: Event) => {
  const img = event.target as HTMLImageElement
  if (img) {
    // Replace with a data URL placeholder to avoid console spam
    img.src =
      'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200"%3E%3Crect width="200" height="200" fill="%23f3f4f6"/%3E%3Cpath d="M70 80h60v40H70z" fill="%23d1d5db"/%3E%3Ccircle cx="85" cy="95" r="8" fill="%23ffffff"/%3E%3Cpath d="M70 110l20-15 15 10 25-20v35H70z" fill="%239ca3af"/%3E%3C/svg%3E'
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

<style scoped>
.fade-in {
  animation: fadeIn 0.3s ease-out;
}

@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(-8px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
