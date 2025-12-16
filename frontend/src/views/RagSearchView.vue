<template>
  <MainLayout>
    <div class="h-full flex flex-col bg-chat" data-testid="page-rag-search">
      <!-- Header -->
      <div
        class="px-6 py-4 border-b border-light-border/30 dark:border-dark-border/20 bg-chat"
        data-testid="section-header"
      >
        <h1 class="text-2xl font-semibold txt-primary mb-1">📚 Semantic Search</h1>
        <p class="txt-secondary text-sm">AI-powered search in your vectorized documents</p>
      </div>

      <div class="flex-1 overflow-y-auto px-6 py-6 scroll-thin">
        <div class="max-w-5xl mx-auto space-y-6">
          <!-- Stats Cards -->
          <div
            v-if="stats"
            class="grid grid-cols-2 md:grid-cols-4 gap-3"
            data-testid="section-stats"
          >
            <div
              class="surface-card p-4 hover:shadow-lg transition-shadow cursor-default"
              data-testid="item-stat-card"
            >
              <div class="flex items-center gap-2 mb-2">
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
                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                  />
                </svg>
              </div>
              <div class="text-2xl font-bold txt-primary">{{ stats.total_documents }}</div>
              <div class="text-sm txt-secondary">Documents</div>
            </div>
            <div
              class="surface-card p-4 hover:shadow-lg transition-shadow cursor-default"
              data-testid="item-stat-card"
            >
              <div class="flex items-center gap-2 mb-2">
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
                    d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"
                  />
                </svg>
              </div>
              <div class="text-2xl font-bold txt-primary">{{ stats.total_chunks }}</div>
              <div class="text-sm txt-secondary">Chunks</div>
            </div>
            <div
              class="surface-card p-4 hover:shadow-lg transition-shadow cursor-default"
              data-testid="item-stat-card"
            >
              <div class="flex items-center gap-2 mb-2">
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
                    d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"
                  />
                </svg>
              </div>
              <div class="text-2xl font-bold txt-primary">{{ stats.total_groups }}</div>
              <div class="text-sm txt-secondary">Groups</div>
            </div>
            <div
              class="surface-card p-4 hover:shadow-lg transition-shadow cursor-default"
              data-testid="item-stat-card"
            >
              <div class="flex items-center gap-2 mb-2">
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
                    d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                  />
                </svg>
              </div>
              <div class="text-2xl font-bold txt-primary">{{ stats.avg_chunk_size }}</div>
              <div class="text-sm txt-secondary">Avg Chars</div>
            </div>
          </div>

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
                    Search Query
                  </span>
                </label>
                <input
                  v-model="query"
                  type="text"
                  placeholder="e.g., 'What is machine learning?' or 'Python programming concepts'"
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
                      Results Limit
                    </span>
                  </label>
                  <select
                    v-model.number="limit"
                    class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] cursor-pointer transition-all"
                    data-testid="input-limit"
                  >
                    <option :value="5">5 results</option>
                    <option :value="10">10 results</option>
                    <option :value="20">20 results</option>
                    <option :value="50">50 results</option>
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
                      Min Similarity
                    </span>
                  </label>
                  <select
                    v-model.number="minScore"
                    class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] cursor-pointer transition-all"
                    data-testid="input-min-score"
                  >
                    <option :value="0.3">30% (More results)</option>
                    <option :value="0.5">50% (Balanced)</option>
                    <option :value="0.7">70% (High quality)</option>
                    <option :value="0.9">90% (Very strict)</option>
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
                      Group Filter
                    </span>
                  </label>
                  <input
                    v-model="groupKey"
                    type="text"
                    placeholder="Optional"
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
                    isSearching ? 'Searching...' : 'Search Documents'
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
                  Found <span class="font-semibold txt-primary">{{ totalResults }}</span> result(s)
                  in <span class="font-semibold txt-primary">{{ searchTime }}ms</span>
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
              <h2 class="text-lg font-semibold txt-primary">Search Results</h2>
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
                    <span class="font-medium txt-primary"
                      >{{ (result.score * 100).toFixed(1) }}% Match</span
                    >
                  </div>
                </div>

                <div class="text-sm txt-secondary">ID: {{ result.message_id }}</div>
              </div>

              <!-- Result Content -->
              <div class="mb-3">
                <p class="txt-secondary whitespace-pre-wrap text-sm leading-relaxed">
                  {{ result.text }}
                </p>
              </div>

              <!-- Result Meta -->
              <div v-if="result.start_line || result.end_line" class="mb-3 text-xs txt-tertiary">
                Lines {{ result.start_line }}-{{ result.end_line }}
              </div>

              <!-- Actions -->
              <div class="flex gap-2">
                <button
                  class="text-sm px-3 py-1.5 rounded-lg hover:bg-[var(--brand)]/10 text-[var(--brand)] transition-colors"
                  data-testid="btn-view-file"
                  @click="viewFile(result.message_id)"
                >
                  View File
                </button>
                <button
                  class="text-sm px-3 py-1.5 rounded-lg hover:bg-[var(--brand)]/10 txt-secondary hover:txt-primary transition-colors"
                  data-testid="btn-find-similar"
                  @click="findSimilarDocs(result.chunk_id)"
                >
                  Find Similar
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
            <h3 class="text-lg font-semibold txt-primary mb-2">No results found</h3>
            <p class="txt-secondary text-sm">
              Try adjusting your search query or lowering the minimum similarity score
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
            <h3 class="text-lg font-semibold txt-primary mb-2">No documents vectorized yet</h3>
            <p class="txt-secondary text-sm mb-4">
              Upload files with "Extract + Vectorize" to enable semantic search
            </p>
            <router-link
              to="/files"
              class="btn-primary px-6 py-2.5 rounded-lg inline-block"
              data-testid="btn-go-files"
            >
              Go to Files
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import MainLayout from '@/components/MainLayout.vue'
import * as ragService from '@/services/ragService'
import { useNotification } from '@/composables/useNotification'

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
    showError('Please enter a search query')
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
        showError('No results found')
      } else {
        showSuccess(`Found ${response.total_results} result(s)`)
      }
    } else {
      showError(response.error || 'Search failed')
      results.value = []
    }
  } catch (error) {
    console.error('Search error:', error)
    showError('Search failed: ' + (error as Error).message)
    results.value = []
  } finally {
    isSearching.value = false
  }
}

const viewFile = (messageId: number) => {
  router.push('/files')
  showSuccess(`Navigate to file with Message ID ${messageId}`)
}

const findSimilarDocs = async (chunkId: number) => {
  try {
    const response = await ragService.findSimilar(chunkId, 5)
    if (response.success && response.results.length > 0) {
      results.value = response.results
      totalResults.value = response.results.length
      showSuccess(`Found ${response.results.length} similar document(s)`)
    } else {
      showError('No similar documents found')
    }
  } catch (error) {
    console.error('Find similar error:', error)
    showError('Failed to find similar documents')
  }
}
</script>
