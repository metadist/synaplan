<template>
  <MainLayout>
    <div class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin">
      <div class="max-w-4xl mx-auto space-y-6">
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
          <div class="flex items-center gap-2 p-1.5 surface-chip rounded-xl">
            <button
              v-for="type in filterTypes"
              :key="type.value"
              type="button"
              class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all"
              :class="
                selectedType === type.value
                  ? 'mode-toggle-active'
                  : 'txt-secondary hover:txt-primary hover:bg-black/5 dark:hover:bg-white/10'
              "
              @click="selectType(type.value)"
            >
              <Icon :icon="type.icon" class="w-4 h-4" />
              {{ type.label }}
              <span class="text-xs opacity-70">({{ type.count }})</span>
            </button>
          </div>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="flex items-center justify-center py-12">
          <Icon icon="mdi:loading" class="w-8 h-8 animate-spin txt-secondary" />
        </div>

        <!-- Empty State -->
        <div
          v-else-if="filteredFeedbacks.length === 0"
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

        <!-- Feedback List -->
        <div v-else class="space-y-3">
          <div
            v-for="feedback in filteredFeedbacks"
            :key="feedback.id"
            class="surface-card rounded-xl p-4 hover:ring-1 hover:ring-brand/20 transition-all"
            :class="{ 'ring-2 ring-brand/50': highlightedId === feedback.id }"
          >
            <div class="flex items-start gap-4">
              <!-- Type Icon -->
              <div
                class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
                :class="feedback.type === 'false_positive' ? 'bg-red-500/10' : 'bg-green-500/10'"
              >
                <Icon
                  :icon="feedback.type === 'false_positive' ? 'mdi:close' : 'mdi:check'"
                  :class="[
                    'w-5 h-5',
                    feedback.type === 'false_positive' ? 'text-red-500' : 'text-green-500',
                  ]"
                />
              </div>

              <!-- Content -->
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <span
                    class="px-2 py-0.5 rounded-full text-xs font-medium"
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
                  <span class="text-xs txt-secondary">
                    {{ formatDate(feedback.created) }}
                  </span>
                </div>

                <!-- Value (editable) -->
                <div v-if="editingId === feedback.id" class="mt-2">
                  <textarea
                    v-model="editValue"
                    rows="3"
                    class="w-full px-4 py-3 rounded-xl surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50 resize-none text-sm"
                  />
                  <div class="flex justify-end gap-2 mt-2">
                    <button
                      type="button"
                      class="px-3 py-1.5 rounded-lg text-sm font-medium surface-chip txt-secondary hover:txt-primary"
                      @click="cancelEdit"
                    >
                      {{ $t('common.cancel') }}
                    </button>
                    <button
                      type="button"
                      class="btn-primary px-3 py-1.5 rounded-lg text-sm font-medium"
                      :disabled="!editValue.trim() || editValue.trim().length < 5"
                      @click="saveEdit(feedback.id)"
                    >
                      {{ $t('common.save') }}
                    </button>
                  </div>
                </div>
                <p v-else class="txt-primary text-sm">{{ feedback.value }}</p>
              </div>

              <!-- Actions -->
              <div v-if="editingId !== feedback.id" class="flex items-center gap-1 shrink-0">
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
      </div>
    </div>

    <!-- Delete Confirmation Dialog -->
    <Teleport to="body">
      <Transition name="fade">
        <div
          v-if="deleteConfirmOpen"
          class="fixed inset-0 bg-black/50 z-[10000] flex items-center justify-center p-4"
          @click.self="deleteConfirmOpen = false"
        >
          <div class="surface-card rounded-2xl shadow-2xl max-w-md w-full p-6">
            <div class="flex items-center gap-3 mb-4">
              <div class="w-10 h-10 rounded-xl bg-red-500/10 flex items-center justify-center">
                <Icon icon="mdi:delete-alert" class="w-5 h-5 text-red-500" />
              </div>
              <h3 class="text-lg font-semibold txt-primary">
                {{ $t('feedback.list.deleteTitle') }}
              </h3>
            </div>
            <p class="txt-secondary text-sm mb-6">
              {{ $t('feedback.list.deleteConfirm') }}
            </p>
            <div class="flex justify-end gap-2">
              <button
                type="button"
                class="px-4 py-2 rounded-xl text-sm font-medium surface-chip txt-secondary hover:txt-primary"
                @click="deleteConfirmOpen = false"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                type="button"
                class="px-4 py-2 rounded-xl text-sm font-medium bg-red-500 text-white hover:bg-red-600"
                @click="executeDelete"
              >
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
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import { useFeedbackStore } from '@/stores/userFeedback'
import type { Feedback } from '@/services/api/userFeedbackApi'

const { t } = useI18n()
const route = useRoute()
const feedbackStore = useFeedbackStore()

// State
const editingId = ref<number | null>(null)
const editValue = ref('')
const deleteConfirmOpen = ref(false)
const deletingFeedback = ref<Feedback | null>(null)
const highlightedId = ref<number | null>(null)

// Store bindings
const loading = computed(() => feedbackStore.loading)
const filteredFeedbacks = computed(() => feedbackStore.filteredFeedbacks)
const selectedType = computed(() => feedbackStore.selectedType)

// Filter types
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

// Format date
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

// Actions
function selectType(type: 'all' | 'false_positive' | 'positive') {
  feedbackStore.selectType(type)
}

function startEdit(feedback: Feedback) {
  editingId.value = feedback.id
  editValue.value = feedback.value
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

function confirmDelete(feedback: Feedback) {
  deletingFeedback.value = feedback
  deleteConfirmOpen.value = true
}

async function executeDelete() {
  if (!deletingFeedback.value) return

  try {
    await feedbackStore.removeFeedback(deletingFeedback.value.id)
  } catch {
    // Error notification handled by store
  } finally {
    deleteConfirmOpen.value = false
    deletingFeedback.value = null
  }
}

// Handle query params for highlighting
watch(
  () => route.query.highlight,
  (id) => {
    if (id) {
      highlightedId.value = parseInt(id as string, 10)
      // Clear highlight after 3 seconds
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

// Load feedbacks on mount
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
