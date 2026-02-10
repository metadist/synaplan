<template>
  <MainLayout>
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin">
      <div class="max-w-4xl mx-auto space-y-5">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 class="text-2xl font-semibold txt-primary flex items-center gap-2">
              <Icon icon="mdi:alert-circle-check-outline" class="w-7 h-7 text-brand" />
              {{ $t('feedback.list.title') }}
            </h1>
            <p class="txt-secondary text-sm mt-1">
              {{ $t('feedback.list.subtitle') }}
            </p>
          </div>

          <!-- Type Filter -->
          <div class="flex items-center gap-1 p-1 surface-chip rounded-xl shrink-0">
            <button
              v-for="ft in filterTypes"
              :key="ft.value"
              type="button"
              class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all"
              :class="
                selectedType === ft.value
                  ? 'mode-toggle-active'
                  : 'txt-secondary hover:txt-primary hover:bg-black/5 dark:hover:bg-white/10'
              "
              @click="selectType(ft.value)"
            >
              <Icon :icon="ft.icon" class="w-3.5 h-3.5" />
              {{ ft.label }}
              <span class="text-[10px] opacity-70">({{ ft.count }})</span>
            </button>
          </div>
        </div>

        <!-- Search + Toolbar -->
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
          <!-- Search -->
          <div class="relative flex-1">
            <Icon
              icon="mdi:magnify"
              class="absolute left-3 top-1/2 -translate-y-1/2 w-4.5 h-4.5 txt-secondary pointer-events-none"
            />
            <input
              v-model="searchQuery"
              type="text"
              class="w-full pl-10 pr-9 py-2.5 rounded-xl surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/40 text-sm"
              :placeholder="$t('feedback.list.searchPlaceholder')"
            />
            <button
              v-if="searchQuery"
              type="button"
              class="absolute right-2.5 top-1/2 -translate-y-1/2 w-5 h-5 rounded-full hover:bg-black/10 dark:hover:bg-white/10 flex items-center justify-center transition-colors"
              @click="searchQuery = ''"
            >
              <Icon icon="mdi:close" class="w-3.5 h-3.5 txt-secondary" />
            </button>
          </div>

          <!-- Bulk actions (visible when items selected) -->
          <Transition name="fade">
            <div v-if="selectedIds.size > 0" class="flex items-center gap-2 shrink-0">
              <span class="text-xs font-medium txt-secondary whitespace-nowrap">
                {{ $t('feedback.list.selectedCount', { count: selectedIds.size }) }}
              </span>
              <button
                type="button"
                class="flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium bg-red-500/10 text-red-600 dark:text-red-400 hover:bg-red-500/20 transition-colors"
                @click="confirmBulkDelete"
              >
                <Icon icon="mdi:delete-outline" class="w-3.5 h-3.5" />
                {{ $t('feedback.list.deleteSelected') }}
              </button>
              <button
                type="button"
                class="w-8 h-8 rounded-lg surface-chip flex items-center justify-center hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                :title="$t('feedback.list.deselectAll')"
                @click="deselectAll"
              >
                <Icon icon="mdi:close" class="w-4 h-4 txt-secondary" />
              </button>
            </div>
          </Transition>
        </div>

        <!-- Loading State -->
        <div v-if="loading && feedbackStore.feedbacks.length === 0" class="flex items-center justify-center py-12">
          <Icon icon="mdi:loading" class="w-8 h-8 animate-spin txt-secondary" />
        </div>

        <!-- Empty State (no feedbacks at all) -->
        <div
          v-else-if="feedbackStore.feedbacks.length === 0"
          class="surface-card rounded-2xl p-8 text-center"
        >
          <div
            class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-brand/20 to-orange-500/20 flex items-center justify-center"
          >
            <Icon icon="mdi:comment-check-outline" class="w-8 h-8 text-brand" />
          </div>
          <h3 class="text-lg font-medium txt-primary mb-2">
            {{ $t('feedback.list.empty') }}
          </h3>
          <p class="txt-secondary text-sm">
            {{ $t('feedback.list.emptyHint') }}
          </p>
        </div>

        <!-- No Search Results -->
        <div
          v-else-if="searchedFeedbacks.length === 0"
          class="surface-card rounded-2xl p-8 text-center"
        >
          <Icon icon="mdi:file-search-outline" class="w-12 h-12 mx-auto mb-3 txt-secondary" />
          <h3 class="text-lg font-medium txt-primary mb-1">
            {{ $t('feedback.list.noResults') }}
          </h3>
          <p class="txt-secondary text-sm">
            {{ $t('feedback.list.noResultsHint') }}
          </p>
        </div>

        <!-- Feedback List -->
        <template v-else>
          <!-- Select-all row -->
          <div class="flex items-center justify-between px-1">
            <label class="flex items-center gap-2 cursor-pointer group">
              <input
                type="checkbox"
                :checked="isAllOnPageSelected"
                :indeterminate="isSomeOnPageSelected && !isAllOnPageSelected"
                class="rounded border-gray-300 text-brand focus:ring-brand cursor-pointer"
                @change="toggleSelectAllOnPage"
              />
              <span class="text-xs txt-secondary group-hover:txt-primary transition-colors">
                {{ isAllOnPageSelected
                  ? $t('feedback.list.deselectAll')
                  : $t('feedback.list.selectAllOnPage')
                }}
              </span>
            </label>
            <span class="text-xs txt-secondary">
              {{ $t('feedback.list.showing', {
                from: (currentPage - 1) * pageSize + 1,
                to: Math.min(currentPage * pageSize, searchedFeedbacks.length),
                total: searchedFeedbacks.length,
              }) }}
            </span>
          </div>

          <div class="space-y-2">
            <div
              v-for="feedback in paginatedFeedbacks"
              :key="feedback.id"
              class="surface-card rounded-xl transition-all group"
              :class="{
                'ring-2 ring-brand/50 shadow-brand/10 shadow-lg': highlightedId === feedback.id,
                'ring-1 ring-brand/20': selectedIds.has(feedback.id) && highlightedId !== feedback.id,
                'hover:ring-1 hover:ring-brand/10': !selectedIds.has(feedback.id) && highlightedId !== feedback.id,
              }"
            >
              <div class="flex items-start gap-3 p-4">
                <!-- Checkbox -->
                <input
                  type="checkbox"
                  :checked="selectedIds.has(feedback.id)"
                  class="mt-1 rounded border-gray-300 text-brand focus:ring-brand cursor-pointer shrink-0"
                  @change="toggleSelect(feedback.id)"
                />

                <!-- Type Icon -->
                <div
                  class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
                  :class="feedback.type === 'false_positive' ? 'bg-red-500/10' : 'bg-green-500/10'"
                >
                  <Icon
                    :icon="feedback.type === 'false_positive' ? 'mdi:close' : 'mdi:check'"
                    :class="[
                      'w-4.5 h-4.5',
                      feedback.type === 'false_positive' ? 'text-red-500' : 'text-green-500',
                    ]"
                  />
                </div>

                <!-- Content -->
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 mb-1">
                    <span
                      class="px-2 py-0.5 rounded-full text-[10px] font-medium"
                      :class="
                        feedback.type === 'false_positive'
                          ? 'bg-red-500/10 text-red-600 dark:text-red-400'
                          : 'bg-green-500/10 text-green-600 dark:text-green-400'
                      "
                    >
                      {{
                        feedback.type === 'false_positive'
                          ? $t('feedback.list.typeFalsePositive')
                          : $t('feedback.list.typePositive')
                      }}
                    </span>
                    <span class="text-[10px] txt-secondary">
                      {{ formatDate(feedback.created) }}
                    </span>
                    <span
                      v-if="feedback.updated !== feedback.created"
                      class="text-[10px] txt-secondary/60"
                    >
                      · {{ $t('feedback.list.edited') }}
                    </span>
                  </div>

                  <!-- Value (editable) -->
                  <div v-if="editingId === feedback.id" class="mt-2">
                    <textarea
                      ref="editTextarea"
                      v-model="editValue"
                      rows="3"
                      class="w-full px-4 py-3 rounded-xl surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50 resize-none text-sm"
                    />
                    <div class="flex justify-end gap-2 mt-2">
                      <button
                        type="button"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium surface-chip txt-secondary hover:txt-primary"
                        @click="cancelEdit"
                      >
                        {{ $t('common.cancel') }}
                      </button>
                      <button
                        type="button"
                        class="btn-primary px-3 py-1.5 rounded-lg text-xs font-medium"
                        :disabled="!editValue.trim() || editValue.trim().length < 5"
                        @click="saveEdit(feedback.id)"
                      >
                        {{ $t('common.save') }}
                      </button>
                    </div>
                  </div>
                  <p
                    v-else
                    class="txt-primary text-sm leading-relaxed"
                    v-html="highlightSearch(feedback.value)"
                  />
                </div>

                <!-- Actions -->
                <div
                  v-if="editingId !== feedback.id"
                  class="flex items-center gap-0.5 shrink-0 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity"
                >
                  <button
                    type="button"
                    class="w-8 h-8 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors"
                    :title="$t('common.edit')"
                    @click="startEdit(feedback)"
                  >
                    <Icon icon="mdi:pencil" class="w-4 h-4 txt-secondary" />
                  </button>
                  <button
                    type="button"
                    class="w-8 h-8 rounded-lg hover:bg-red-500/10 flex items-center justify-center transition-colors"
                    :title="$t('common.delete')"
                    @click="confirmDelete(feedback)"
                  >
                    <Icon icon="mdi:delete" class="w-4 h-4 text-red-500" />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Pagination -->
          <div
            v-if="totalPages > 1"
            class="flex items-center justify-center gap-1.5 pt-2"
          >
            <button
              type="button"
              class="w-8 h-8 rounded-lg surface-chip flex items-center justify-center transition-colors"
              :class="currentPage === 1 ? 'opacity-40 cursor-not-allowed' : 'hover:bg-black/5 dark:hover:bg-white/5'"
              :disabled="currentPage === 1"
              @click="currentPage = currentPage - 1"
            >
              <Icon icon="mdi:chevron-left" class="w-4.5 h-4.5 txt-secondary" />
            </button>

            <template v-for="page in visiblePages" :key="page">
              <span v-if="page === '...'" class="w-8 h-8 flex items-center justify-center text-xs txt-secondary">
                …
              </span>
              <button
                v-else
                type="button"
                class="w-8 h-8 rounded-lg text-xs font-medium transition-colors"
                :class="currentPage === page
                  ? 'bg-brand text-white'
                  : 'surface-chip txt-secondary hover:txt-primary hover:bg-black/5 dark:hover:bg-white/5'"
                @click="currentPage = page as number"
              >
                {{ page }}
              </button>
            </template>

            <button
              type="button"
              class="w-8 h-8 rounded-lg surface-chip flex items-center justify-center transition-colors"
              :class="currentPage === totalPages ? 'opacity-40 cursor-not-allowed' : 'hover:bg-black/5 dark:hover:bg-white/5'"
              :disabled="currentPage === totalPages"
              @click="currentPage = currentPage + 1"
            >
              <Icon icon="mdi:chevron-right" class="w-4.5 h-4.5 txt-secondary" />
            </button>
          </div>
        </template>
      </div>
    </div>

    <!-- Delete Confirmation Dialog (single + bulk) -->
    <Teleport to="body">
      <Transition name="fade">
        <div
          v-if="deleteConfirmOpen"
          class="fixed inset-0 bg-black/50 z-[10000] flex items-center justify-center p-4"
          @click.self="closeDeleteConfirm"
        >
          <div class="surface-card rounded-2xl shadow-2xl max-w-md w-full p-6" @click.stop>
            <div class="flex items-center gap-3 mb-4">
              <div class="w-10 h-10 rounded-xl bg-red-500/10 flex items-center justify-center">
                <Icon icon="mdi:delete-alert" class="w-5 h-5 text-red-500" />
              </div>
              <div>
                <h3 class="text-lg font-semibold txt-primary">
                  {{ isBulkDelete
                    ? $t('feedback.list.bulkDeleteTitle')
                    : $t('feedback.list.deleteTitle')
                  }}
                </h3>
                <p v-if="isBulkDelete" class="text-xs txt-secondary mt-0.5">
                  {{ $t('feedback.list.bulkDeleteCount', { count: selectedIds.size }) }}
                </p>
              </div>
            </div>
            <p class="txt-secondary text-sm mb-6">
              {{ isBulkDelete
                ? $t('feedback.list.bulkDeleteConfirm')
                : $t('feedback.list.deleteConfirm')
              }}
            </p>
            <div class="flex justify-end gap-2">
              <button
                type="button"
                class="px-4 py-2 rounded-xl text-sm font-medium surface-chip txt-secondary hover:txt-primary"
                :disabled="deleteLoading"
                @click="closeDeleteConfirm"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                type="button"
                class="px-4 py-2 rounded-xl text-sm font-medium bg-red-500 text-white hover:bg-red-600 flex items-center gap-2"
                :disabled="deleteLoading"
                @click="executeDelete"
              >
                <Icon v-if="deleteLoading" icon="mdi:loading" class="w-4 h-4 animate-spin" />
                {{ $t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import { useFeedbackStore } from '@/stores/userFeedback'
import type { Feedback } from '@/services/api/userFeedbackApi'

const { t } = useI18n()
const route = useRoute()
const feedbackStore = useFeedbackStore()

// ── State ──────────────────────────────────────────────
const searchQuery = ref('')
const editingId = ref<number | null>(null)
const editValue = ref('')
const editTextarea = ref<HTMLTextAreaElement | null>(null)
const deleteConfirmOpen = ref(false)
const deletingFeedback = ref<Feedback | null>(null)
const isBulkDelete = ref(false)
const deleteLoading = ref(false)
const highlightedId = ref<number | null>(null)
const selectedIds = ref<Set<number>>(new Set())
const currentPage = ref(1)
const pageSize = 20

// ── Store bindings ─────────────────────────────────────
const loading = computed(() => feedbackStore.loading)
const selectedType = computed(() => feedbackStore.selectedType)

// ── Filter types ───────────────────────────────────────
const filterTypes = computed(() => [
  {
    value: 'all' as const,
    label: t('feedback.list.filterAll'),
    icon: 'mdi:format-list-bulleted',
    count: feedbackStore.totalCount,
  },
  {
    value: 'false_positive' as const,
    label: t('feedback.list.filterFalsePositive'),
    icon: 'mdi:close-circle',
    count: feedbackStore.falsePositiveCount,
  },
  {
    value: 'positive' as const,
    label: t('feedback.list.filterPositive'),
    icon: 'mdi:check-circle',
    count: feedbackStore.positiveCount,
  },
])

// ── Computed: search + filter ──────────────────────────
const searchedFeedbacks = computed(() => {
  let items = feedbackStore.filteredFeedbacks
  const query = searchQuery.value.trim().toLowerCase()
  if (query) {
    items = items.filter((f) => f.value.toLowerCase().includes(query))
  }
  return items
})

// ── Computed: pagination ───────────────────────────────
const totalPages = computed(() => Math.max(1, Math.ceil(searchedFeedbacks.value.length / pageSize)))

const paginatedFeedbacks = computed(() => {
  const start = (currentPage.value - 1) * pageSize
  return searchedFeedbacks.value.slice(start, start + pageSize)
})

const visiblePages = computed(() => {
  const total = totalPages.value
  const current = currentPage.value
  if (total <= 7) {
    return Array.from({ length: total }, (_, i) => i + 1)
  }
  const pages: (number | string)[] = [1]
  if (current > 3) pages.push('...')
  for (let i = Math.max(2, current - 1); i <= Math.min(total - 1, current + 1); i++) {
    pages.push(i)
  }
  if (current < total - 2) pages.push('...')
  pages.push(total)
  return pages
})

// ── Computed: selection ────────────────────────────────
const pageIds = computed(() => new Set(paginatedFeedbacks.value.map((f) => f.id)))

const isAllOnPageSelected = computed(
  () => pageIds.value.size > 0 && [...pageIds.value].every((id) => selectedIds.value.has(id))
)

const isSomeOnPageSelected = computed(
  () => [...pageIds.value].some((id) => selectedIds.value.has(id))
)

// ── Watchers ───────────────────────────────────────────
// Reset page when filters change
watch([searchQuery, selectedType], () => {
  currentPage.value = 1
  selectedIds.value = new Set()
})

// Clamp page if filtered list shrinks
watch(totalPages, (tp) => {
  if (currentPage.value > tp) currentPage.value = tp
})

// Handle query params for highlighting
watch(
  () => route.query.highlight,
  (id) => {
    if (id) {
      highlightedId.value = parseInt(id as string, 10)
      setTimeout(() => {
        highlightedId.value = null
      }, 3000)
    }
  },
  { immediate: true }
)

// Handle query params for editing
watch(
  () => route.query.edit,
  (id) => {
    if (id) {
      const feedbackId = parseInt(id as string, 10)
      const feedback = feedbackStore.getFeedbackById(feedbackId)
      if (feedback) {
        startEdit(feedback)
      }
    }
  },
  { immediate: true }
)

// ── Helpers ────────────────────────────────────────────
function formatDate(timestamp: number): string {
  if (!timestamp) return ''
  const date = new Date(timestamp * 1000)
  return date.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function highlightSearch(text: string): string {
  const query = searchQuery.value.trim()
  if (!query) return escapeHtml(text)
  const escaped = escapeHtml(text)
  const regex = new RegExp(`(${escapeRegex(query)})`, 'gi')
  return escaped.replace(regex, '<mark class="bg-brand/20 text-brand rounded px-0.5">$1</mark>')
}

function escapeHtml(str: string): string {
  return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

function escapeRegex(str: string): string {
  return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

// ── Actions: type filter ───────────────────────────────
function selectType(type: 'all' | 'false_positive' | 'positive') {
  feedbackStore.selectType(type)
}

// ── Actions: selection ─────────────────────────────────
function toggleSelect(id: number) {
  const next = new Set(selectedIds.value)
  if (next.has(id)) {
    next.delete(id)
  } else {
    next.add(id)
  }
  selectedIds.value = next
}

function toggleSelectAllOnPage() {
  if (isAllOnPageSelected.value) {
    // Deselect all on current page
    const next = new Set(selectedIds.value)
    for (const id of pageIds.value) {
      next.delete(id)
    }
    selectedIds.value = next
  } else {
    // Select all on current page
    const next = new Set(selectedIds.value)
    for (const id of pageIds.value) {
      next.add(id)
    }
    selectedIds.value = next
  }
}

function deselectAll() {
  selectedIds.value = new Set()
}

// ── Actions: edit ──────────────────────────────────────
function startEdit(feedback: Feedback) {
  editingId.value = feedback.id
  editValue.value = feedback.value
  nextTick(() => {
    // ref inside v-for returns an array — grab the first (only) visible element
    const el = Array.isArray(editTextarea.value) ? editTextarea.value[0] : editTextarea.value
    ;(el as HTMLTextAreaElement | undefined)?.focus()
  })
}

function cancelEdit() {
  editingId.value = null
  editValue.value = ''
}

async function saveEdit(id: number) {
  if (!editValue.value.trim() || editValue.value.trim().length < 5) return

  try {
    await feedbackStore.editFeedback(id, { value: editValue.value.trim() })
    cancelEdit()
  } catch {
    // Error notification handled by store
  }
}

// ── Actions: delete ────────────────────────────────────
function confirmDelete(feedback: Feedback) {
  deletingFeedback.value = feedback
  isBulkDelete.value = false
  deleteConfirmOpen.value = true
}

function confirmBulkDelete() {
  deletingFeedback.value = null
  isBulkDelete.value = true
  deleteConfirmOpen.value = true
}

function closeDeleteConfirm() {
  deleteConfirmOpen.value = false
  deletingFeedback.value = null
  isBulkDelete.value = false
}

async function executeDelete() {
  deleteLoading.value = true
  try {
    if (isBulkDelete.value) {
      const ids = [...selectedIds.value]
      await feedbackStore.bulkRemoveFeedbacks(ids)
      selectedIds.value = new Set()
    } else if (deletingFeedback.value) {
      await feedbackStore.removeFeedback(deletingFeedback.value.id)
      // Remove from selection if selected
      if (selectedIds.value.has(deletingFeedback.value.id)) {
        const next = new Set(selectedIds.value)
        next.delete(deletingFeedback.value.id)
        selectedIds.value = next
      }
    }
  } catch {
    // Error notification handled by store
  } finally {
    deleteLoading.value = false
    closeDeleteConfirm()
  }
}

// ── Lifecycle ──────────────────────────────────────────
onMounted(async () => {
  await feedbackStore.fetchFeedbacks()
})
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
