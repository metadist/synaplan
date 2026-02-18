<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="isOpen"
        class="fixed inset-0 bg-black/50 z-[100] flex items-center justify-center p-2 sm:p-4"
        @click.self="close"
      >
        <div
          class="surface-card rounded-xl shadow-2xl w-full max-w-4xl max-h-[95vh] sm:max-h-[90vh] flex flex-col overflow-hidden"
          data-testid="modal-memories-dialog"
          @click.stop
        >
          <!-- Header -->
          <div
            class="flex items-center justify-between p-4 sm:p-6 border-b border-light-border/10 dark:border-dark-border/10"
          >
            <div class="flex items-center gap-2 sm:gap-3 min-w-0">
              <div
                class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-brand/10 flex items-center justify-center shrink-0"
              >
                <Icon icon="mdi:brain" class="w-5 h-5 sm:w-6 sm:h-6 text-brand" />
              </div>
              <div class="min-w-0">
                <h2 class="text-lg sm:text-xl font-semibold txt-primary truncate">
                  {{ $t('memories.title') }}
                </h2>
                <p class="text-xs sm:text-sm txt-secondary truncate hidden sm:block">
                  {{ $t('memories.description') }}
                </p>
              </div>
            </div>
            <button
              class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors shrink-0"
              @click="close"
            >
              <Icon icon="mdi:close" class="w-5 h-5 txt-secondary" />
            </button>
          </div>

          <!-- Toolbar -->
          <div
            class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3 p-3 sm:p-4 border-b border-light-border/10 dark:border-dark-border/10"
          >
            <div class="flex-1 relative">
              <Icon
                icon="mdi:magnify"
                class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 txt-secondary"
              />
              <input
                v-model="searchQuery"
                type="text"
                :placeholder="$t('memories.search.placeholder')"
                class="w-full pl-10 pr-4 py-2 sm:py-2.5 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50 transition-all text-sm sm:text-base"
              />
            </div>
            <div class="flex gap-2 sm:gap-3">
              <select
                v-model="selectedCategory"
                class="flex-1 sm:flex-initial px-3 sm:px-4 py-2 sm:py-2.5 rounded-lg surface-chip txt-primary focus:outline-none focus:ring-2 focus:ring-brand/50 transition-all cursor-pointer text-sm sm:text-base"
              >
                <option value="all">{{ $t('memories.categories.all') }}</option>
                <option
                  v-for="cat in availableCategories"
                  :key="cat.category"
                  :value="cat.category"
                >
                  {{ $t(`memories.categories.${cat.category}`, cat.category) }} ({{ cat.count }})
                </option>
              </select>
              <button
                class="btn-primary px-3 sm:px-4 py-2 sm:py-2.5 rounded-lg flex items-center gap-2 font-medium text-sm sm:text-base shrink-0"
                @click="openCreateDialog"
              >
                <Icon icon="mdi:plus" class="w-5 h-5" />
                <span class="hidden sm:inline">{{ $t('memories.actions.create') }}</span>
              </button>
            </div>
          </div>

          <!-- Content -->
          <div class="flex-1 overflow-y-auto scroll-thin p-3 sm:p-4">
            <div v-if="loading" class="flex items-center justify-center py-12">
              <svg class="w-8 h-8 animate-spin txt-brand" fill="none" viewBox="0 0 24 24">
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
            </div>

            <div v-else-if="filteredMemories.length === 0" class="text-center py-12 px-4">
              <Icon
                icon="mdi:brain-off"
                class="w-12 h-12 sm:w-16 sm:h-16 mx-auto txt-secondary opacity-50 mb-4"
              />
              <h3 class="text-base sm:text-lg font-semibold txt-primary mb-2">
                {{ searchQuery ? $t('memories.search.noResults') : $t('memories.empty') }}
              </h3>
              <p class="txt-secondary text-xs sm:text-sm">{{ $t('memories.emptyDesc') }}</p>
            </div>

            <div v-else class="space-y-2 sm:space-y-3">
              <div
                v-for="memory in filteredMemories"
                :key="memory.id"
                :ref="
                  (el) => {
                    if (el) memoryRefs[memory.id] = el as HTMLElement
                  }
                "
                :class="[
                  'surface-chip rounded-lg p-3 sm:p-4 hover-surface transition-all',
                  highlightMemoryId === memory.id
                    ? 'ring-2 ring-brand bg-brand/5 dark:bg-brand/10'
                    : '',
                ]"
              >
                <div
                  class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2 sm:gap-3 mb-2"
                >
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1 flex-wrap">
                      <span class="text-sm font-medium txt-primary break-all">{{
                        memory.key
                      }}</span>
                      <span class="pill text-xs px-2 py-0.5 sm:py-1">{{ memory.category }}</span>
                      <span class="text-xs txt-secondary"
                        >• {{ $t(`memories.source.${memory.source}`) }}</span
                      >
                    </div>
                    <p class="txt-primary text-sm break-words">{{ memory.value }}</p>
                  </div>
                  <div class="flex items-center gap-1 self-end sm:self-start shrink-0">
                    <button
                      class="icon-ghost w-8 h-8 rounded-lg flex items-center justify-center"
                      :title="$t('memories.actions.edit')"
                      @click="editMemory(memory)"
                    >
                      <Icon icon="mdi:pencil" class="w-4 h-4" />
                    </button>
                    <button
                      class="icon-ghost icon-ghost--danger w-8 h-8 rounded-lg flex items-center justify-center"
                      :title="$t('memories.actions.delete')"
                      @click="deleteMemory(memory)"
                    >
                      <Icon icon="mdi:delete" class="w-4 h-4" />
                    </button>
                  </div>
                </div>
                <div
                  class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 text-xs txt-secondary"
                >
                  <span>{{ $t('common.created') }}: {{ formatDate(memory.created) }}</span>
                  <span>{{ $t('common.updated') }}: {{ formatDate(memory.updated) }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer with View All -->
          <div
            class="flex items-center justify-between p-3 sm:p-4 border-t border-light-border/10 dark:border-dark-border/10"
          >
            <p class="text-xs sm:text-sm txt-secondary">
              {{
                $t('memories.totalCount', {
                  count: memoriesStore.memories.length,
                })
              }}
            </p>
            <button
              class="pill pill--active px-4 py-2 text-sm font-semibold rounded-lg transition-all flex items-center gap-2 hover:opacity-80"
              @click="viewAllMemories"
            >
              {{ $t('memories.viewAll') }}
              <Icon icon="mdi:arrow-right" class="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Create/Edit Dialog -->
    <MemoryFormDialog
      :is-open="showFormDialog"
      :memory="editingMemory"
      :available-categories="availableCategories.map((c) => c.category)"
      @close="closeFormDialog"
      @save="handleSaveMemory"
      @save-multiple="handleSaveMultiple"
    />
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useMemoriesStore } from '@/stores/userMemories'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import type { UserMemory } from '@/services/api/userMemoriesApi'
import { getCategories } from '@/services/api/userMemoriesApi'
import MemoryFormDialog from '@/components/MemoryFormDialog.vue'

interface ParsedAction {
  action: 'create' | 'update' | 'delete'
  memory?: { category: string; key: string; value: string }
  existingId?: number
  reason?: string
}

interface Props {
  isOpen: boolean
  highlightMemoryId?: number | null
}

interface Emits {
  (e: 'close'): void
}

const props = withDefaults(defineProps<Props>(), {
  highlightMemoryId: null,
})
const emit = defineEmits<Emits>()

const { t, locale } = useI18n()
const router = useRouter()
const memoriesStore = useMemoriesStore()
const { confirm } = useDialog()
const { success, error } = useNotification()

const loading = ref(false)
const searchQuery = ref('')
const selectedCategory = ref('all')
const showFormDialog = ref(false)
const editingMemory = ref<UserMemory | null>(null)
const availableCategories = ref<Array<{ category: string; count: number }>>([])
const memoryRefs = ref<Record<number, HTMLElement | null>>({})

const filteredMemories = computed(() => {
  let memories = memoriesStore.memories

  // Filter by category
  if (selectedCategory.value !== 'all') {
    memories = memories.filter((m) => m.category === selectedCategory.value)
  }

  // Filter by search query
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    memories = memories.filter(
      (m) =>
        m.key.toLowerCase().includes(query) ||
        m.value.toLowerCase().includes(query) ||
        m.category.toLowerCase().includes(query)
    )
  }

  // Limit to 10 most recent memories (unless searching/filtering or highlighting a specific memory)
  if (!searchQuery.value && selectedCategory.value === 'all' && !props.highlightMemoryId) {
    return memories.slice(0, 10)
  }

  // If highlighting a specific memory, show all memories so it's visible
  return memories
})

watch(
  () => props.isOpen,
  async (isOpen) => {
    if (isOpen) {
      await loadMemories()
      // Scroll to highlighted memory if specified
      if (props.highlightMemoryId) {
        await nextTick()
        setTimeout(() => {
          const el = memoryRefs.value[props.highlightMemoryId!]
          if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' })
          }
        }, 100)
      }
    }
  }
)

async function loadMemories() {
  loading.value = true
  try {
    await memoriesStore.fetchMemories()
    availableCategories.value = await getCategories()
  } catch {
    // Store handles error notifications
  } finally {
    loading.value = false
  }
}

function close() {
  emit('close')
}

function openCreateDialog() {
  editingMemory.value = null
  showFormDialog.value = true
}

function editMemory(memory: UserMemory) {
  editingMemory.value = memory
  showFormDialog.value = true
}

async function deleteMemory(memory: UserMemory) {
  const confirmed = await confirm({
    title: t('memories.delete.title'),
    message: t('memories.delete.message'),
    confirmText: t('memories.delete.confirm'),
    danger: true,
  })

  if (!confirmed) return

  // Store handles notifications
  await memoriesStore.removeMemory(memory.id)
}

function closeFormDialog() {
  showFormDialog.value = false
  editingMemory.value = null
}

async function handleSaveMemory(memoryData: Partial<UserMemory>) {
  try {
    if (editingMemory.value) {
      // Update: only send value
      await memoriesStore.editMemory(editingMemory.value.id, { value: memoryData.value || '' })
    } else {
      // Create: only send category, key, value (no source!)
      await memoriesStore.addMemory({
        category: memoryData.category || 'preferences',
        key: memoryData.key || '',
        value: memoryData.value || '',
      })
    }
    // Store handles notifications
    closeFormDialog()
  } catch {
    // Store already shows error notification
  }
}

async function handleSaveMultiple(actions: ParsedAction[]) {
  let successCount = 0
  let errorCount = 0

  // Use silent mode for all operations, we'll show one notification at the end
  for (const actionItem of actions) {
    try {
      if (actionItem.action === 'create' && actionItem.memory) {
        await memoriesStore.addMemory(
          {
            category: actionItem.memory.category,
            key: actionItem.memory.key,
            value: actionItem.memory.value,
          },
          { silent: true }
        )
        successCount++
      } else if (actionItem.action === 'update' && actionItem.existingId && actionItem.memory) {
        // Validate that the memory exists before trying to update
        const memoryExists = memoriesStore.memories.some((m) => m.id === actionItem.existingId)
        if (memoryExists) {
          await memoriesStore.editMemory(
            actionItem.existingId,
            {
              value: actionItem.memory.value,
            },
            { silent: true }
          )
          successCount++
        } else {
          // Memory doesn't exist locally, create instead
          await memoriesStore.addMemory(
            {
              category: actionItem.memory.category,
              key: actionItem.memory.key,
              value: actionItem.memory.value,
            },
            { silent: true }
          )
          successCount++
        }
      } else if (actionItem.action === 'delete' && actionItem.existingId) {
        // Validate that the memory exists before trying to delete
        const memoryExists = memoriesStore.memories.some((m) => m.id === actionItem.existingId)
        if (memoryExists) {
          await memoriesStore.removeMemory(actionItem.existingId, { silent: true })
          successCount++
        } else {
          // Memory doesn't exist, skip silently
          console.warn('Memory to delete not found:', actionItem.existingId)
        }
      }
    } catch (err) {
      errorCount++
      console.error('Error processing action:', err)
    }
  }

  closeFormDialog()
  await loadMemories()

  // Show single notification for the batch operation
  if (successCount > 0 && errorCount === 0) {
    if (successCount === 1) {
      success(t('memories.createSuccess'))
    } else {
      success(t('memories.multipleSuccess', { count: successCount }))
    }
  } else if (successCount > 0 && errorCount > 0) {
    success(t('memories.partialSuccess', { success: successCount, error: errorCount }))
  } else if (errorCount > 0) {
    error(t('memories.createError'))
  }
}

function formatDate(date: Date | number | string): string {
  // Backend returns unix timestamps in SECONDS (e.g. 1705234567)
  // JS Date expects milliseconds, so convert when needed.
  const d =
    typeof date === 'number'
      ? new Date(date < 10_000_000_000 ? date * 1000 : date)
      : typeof date === 'string'
        ? new Date(date)
        : date
  const browserLocale = typeof navigator !== 'undefined' ? navigator.language : 'en'
  const activeLocale = (locale?.value || browserLocale) as string
  return new Intl.DateTimeFormat(activeLocale, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(d)
}

function viewAllMemories() {
  close()
  router.push('/memories')
}
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
