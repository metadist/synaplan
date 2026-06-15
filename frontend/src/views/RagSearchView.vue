<template>
  <MainLayout>
    <div
      class="h-full flex flex-col bg-chat overflow-y-auto scroll-thin"
      data-testid="page-rag-search"
    >
      <div class="px-3 py-4 sm:p-4 md:p-8">
        <div class="max-w-7xl mx-auto space-y-6">
          <!-- §4.8: the knowledge base has two tabs — Files (browse) + Search -->
          <FilesTabs active="search" />

          <!-- §4.8 #4: one compact status line instead of 4 jargon stat cards -->
          <p v-if="stats" class="text-sm txt-secondary" data-testid="section-stats">
            {{
              $t('rag.statusLine', {
                docs: stats.total_documents,
                folders: stats.total_groups,
              })
            }}
          </p>

          <!-- Search Box -->
          <div class="surface-card p-6" data-testid="section-search">
            <form class="space-y-4" data-testid="form-rag-search" @submit.prevent="performSearch">
              <div>
                <label class="block text-sm font-medium txt-primary mb-2">
                  <span class="flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                      />
                    </svg>
                    {{ $t('rag.searchQuery') }}
                  </span>
                </label>
                <input
                  v-model="query"
                  type="text"
                  :placeholder="$t('rag.searchPlaceholder')"
                  class="w-full px-4 py-3 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
                  :disabled="isSearching"
                  data-testid="input-query"
                  @keydown.enter.prevent="performSearch"
                />
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label class="block text-sm font-medium txt-primary mb-2">
                    <span class="flex items-center gap-2">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"
                        />
                      </svg>
                      {{ $t('rag.resultsLimit') }}
                    </span>
                  </label>
                  <select
                    v-model.number="limit"
                    class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] cursor-pointer transition-all"
                    data-testid="input-limit"
                  >
                    <option :value="5">{{ $t('rag.nResults', { n: 5 }) }}</option>
                    <option :value="10">{{ $t('rag.nResults', { n: 10 }) }}</option>
                    <option :value="20">{{ $t('rag.nResults', { n: 20 }) }}</option>
                    <option :value="50">{{ $t('rag.nResults', { n: 50 }) }}</option>
                  </select>
                </div>

                <div>
                  <label class="block text-sm font-medium txt-primary mb-2">
                    <span class="flex items-center gap-2">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                        />
                      </svg>
                      {{ $t('rag.minSimilarity') }}
                    </span>
                  </label>
                  <select
                    v-model.number="minScore"
                    class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] cursor-pointer transition-all"
                    data-testid="input-min-score"
                  >
                    <option :value="0.3">{{ $t('rag.simMore') }}</option>
                    <option :value="0.5">{{ $t('rag.simBalanced') }}</option>
                    <option :value="0.7">{{ $t('rag.simHigh') }}</option>
                    <option :value="0.9">{{ $t('rag.simStrict') }}</option>
                  </select>
                </div>

                <div>
                  <label class="block text-sm font-medium txt-primary mb-2">
                    <span class="flex items-center gap-2">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                          stroke-linecap="round"
                          stroke-linejoin="round"
                          stroke-width="2"
                          d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"
                        />
                      </svg>
                      {{ $t('rag.groupFilter') }}
                    </span>
                  </label>
                  <input
                    v-model="groupKey"
                    type="text"
                    :placeholder="$t('rag.optional')"
                    class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
                    data-testid="input-group-key"
                  />
                </div>
              </div>

              <div class="flex items-center gap-4 flex-wrap" data-testid="bar-search-actions">
                <button
                  type="submit"
                  :disabled="isSearching || !query.trim()"
                  class="btn-primary px-8 py-3 rounded-lg flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed hover:scale-105 transition-transform"
                  data-testid="btn-search"
                >
                  <svg
                    v-if="!isSearching"
                    class="w-5 h-5"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
                    />
                  </svg>
                  <svg
                    v-else
                    class="animate-spin h-5 w-5"
                    xmlns="http://www.w3.org/2000/svg"
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
                    ></circle>
                    <path
                      class="opacity-75"
                      fill="currentColor"
                      d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                    ></path>
                  </svg>
                  <span class="font-medium">{{
                    isSearching ? $t('rag.searching') : $t('rag.searchButton')
                  }}</span>
                </button>

                <div
                  v-if="searchTime"
                  class="flex items-center gap-2 text-sm txt-secondary"
                  data-testid="text-search-summary"
                >
                  <svg
                    class="w-4 h-4 text-[var(--brand)]"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                    />
                  </svg>
                  {{ $t('rag.foundSummary', { n: totalResults, ms: searchTime }) }}
                </div>
              </div>
            </form>
          </div>

          <!-- Results -->
          <div v-if="results.length > 0" class="space-y-4" data-testid="section-results">
            <div class="flex items-center gap-2 mb-4">
              <svg
                class="w-5 h-5 text-[var(--brand)]"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
                />
              </svg>
              <h2 class="text-lg font-semibold txt-primary">{{ $t('rag.searchResults') }}</h2>
            </div>

            <div
              v-for="(result, index) in results"
              :key="result.chunk_id"
              class="surface-card p-5 hover:shadow-lg transition-all cursor-default"
              data-testid="item-result"
            >
              <!-- Result Header -->
              <div class="flex items-start justify-between mb-3">
                <div class="flex items-center gap-3">
                  <div
                    class="px-3 py-1 rounded-full bg-[var(--brand)]/10 text-[var(--brand)] text-sm font-semibold"
                  >
                    #{{ index + 1 }}
                  </div>
                  <div class="flex items-center gap-2">
                    <svg
                      class="w-5 h-5 text-[var(--brand)]"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                      />
                    </svg>
                    <span class="font-medium txt-primary">{{
                      $t('rag.match', { pct: (result.score * 100).toFixed(1) })
                    }}</span>
                  </div>
                </div>

                <div class="text-sm txt-secondary">
                  {{ $t('rag.idLabel', { id: result.message_id }) }}
                </div>
              </div>

              <!-- Result Content -->
              <div class="mb-3">
                <p class="txt-secondary whitespace-pre-wrap text-sm leading-relaxed">
                  {{ result.text }}
                </p>
              </div>

              <!-- Result Meta -->
              <div v-if="result.start_line || result.end_line" class="mb-3 text-xs txt-tertiary">
                {{ $t('rag.lines', { start: result.start_line, end: result.end_line }) }}
              </div>

              <!-- Actions -->
              <div class="flex gap-2">
                <button
                  class="text-sm px-3 py-1.5 rounded-lg hover:bg-[var(--brand)]/10 text-[var(--brand)] transition-colors"
                  data-testid="btn-view-file"
                  @click="viewFile(result.message_id)"
                >
                  {{ $t('rag.viewFile') }}
                </button>
                <button
                  class="text-sm px-3 py-1.5 rounded-lg hover:bg-[var(--brand)]/10 txt-secondary hover:txt-primary transition-colors"
                  data-testid="btn-find-similar"
                  @click="findSimilarDocs(result.chunk_id)"
                >
                  {{ $t('rag.findSimilar') }}
                </button>
              </div>
            </div>
          </div>

          <!-- Empty State -->
          <div
            v-else-if="hasSearched && !isSearching"
            class="surface-card p-12 text-center"
            data-testid="state-no-results"
          >
            <svg
              class="w-16 h-16 mx-auto mb-4 txt-secondary opacity-50"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
              />
            </svg>
            <h3 class="text-lg font-semibold txt-primary mb-2">{{ $t('rag.noResultsTitle') }}</h3>
            <p class="txt-secondary text-sm">
              {{ $t('rag.noResultsHint') }}
            </p>
          </div>

          <!-- No Documents State -->
          <div
            v-else-if="stats && stats.total_documents === 0 && !isSearching"
            class="surface-card p-12 text-center"
            data-testid="state-no-docs"
          >
            <svg
              class="w-16 h-16 mx-auto mb-4 txt-secondary opacity-50"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
              />
            </svg>
            <h3 class="text-lg font-semibold txt-primary mb-2">{{ $t('rag.noDocsTitle') }}</h3>
            <p class="txt-secondary text-sm mb-4">
              {{ $t('rag.noDocsHint') }}
            </p>
            <router-link
              to="/files"
              class="btn-primary px-6 py-2.5 rounded-lg inline-block"
              data-testid="btn-go-files"
            >
              {{ $t('rag.goToFiles') }}
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import MainLayout from '@/components/MainLayout.vue'
import FilesTabs from '@/components/files/FilesTabs.vue'
import * as ragService from '@/services/ragService'
import { useNotification } from '@/composables/useNotification'

const { t } = useI18n()
const router = useRouter()
const { success: showSuccess, error: showError } = useNotification()

const query = ref('')
const limit = ref(10)
const minScore = ref(0.5)
const groupKey = ref('')

const isSearching = ref(false)
const hasSearched = ref(false)
const results = ref<ragService.RagSearchResult[]>([])
const totalResults = ref(0)
const searchTime = ref(0)

const stats = ref<ragService.RagStats | null>(null)

onMounted(async () => {
  await loadStats()
})

const loadStats = async () => {
  try {
    const response = await ragService.getStats()
    stats.value = response.stats
  } catch (error) {
    console.error('Failed to load stats:', error)
  }
}

const performSearch = async () => {
  if (!query.value.trim()) {
    showError(t('rag.enterQuery'))
    return
  }

  isSearching.value = true
  hasSearched.value = true

  try {
    const response = await ragService.search({
      query: query.value,
      limit: limit.value,
      min_score: minScore.value,
      group_key: groupKey.value || undefined,
    })

    if (response.success) {
      results.value = response.results
      totalResults.value = response.total_results
      searchTime.value = response.search_time_ms

      if (response.results.length === 0) {
        showError(t('rag.noResultsTitle'))
      } else {
        showSuccess(t('rag.foundToast', { n: response.total_results }))
      }
    } else {
      showError(response.error || t('rag.searchFailed'))
      results.value = []
    }
  } catch (error) {
    console.error('Search error:', error)
    showError(t('rag.searchFailed') + ': ' + (error as Error).message)
    results.value = []
  } finally {
    isSearching.value = false
  }
}

const viewFile = (messageId: number) => {
  router.push('/files')
  showSuccess(t('rag.navigateToFile', { id: messageId }))
}

const findSimilarDocs = async (chunkId: number | string) => {
  try {
    const response = await ragService.findSimilar(chunkId, 5)
    if (response.success && response.results.length > 0) {
      results.value = response.results
      totalResults.value = response.results.length
      showSuccess(t('rag.similarFound', { n: response.results.length }))
    } else {
      showError(t('rag.noSimilar'))
    }
  } catch (error) {
    console.error('Find similar error:', error)
    showError(t('rag.similarFailed'))
  }
}
</script>
