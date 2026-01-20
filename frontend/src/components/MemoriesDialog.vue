<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="isOpen"
        class="fixed inset-0 bg-black/50 z-[100] flex items-center justify-center p-4"
        @click.self="close"
      >
        <div
          class="surface-card rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] flex flex-col overflow-hidden"
          @click.stop
        >
          <!-- Header -->
          <div
            class="flex items-center justify-between p-6 border-b border-light-border/10 dark:border-dark-border/10"
          >
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-full bg-brand/10 flex items-center justify-center">
                <Icon icon="mdi:brain" class="w-6 h-6 text-brand" />
              </div>
              <div>
                <h2 class="text-xl font-semibold txt-primary">{{ $t('memories.title') }}</h2>
                <p class="text-sm txt-secondary">{{ $t('memories.description') }}</p>
              </div>
            </div>
            <button
              class="w-10 h-10 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors"
              @click="close"
            >
              <Icon icon="mdi:close" class="w-5 h-5 txt-secondary" />
            </button>
          </div>

          <!-- Toolbar -->
          <div
            class="flex items-center gap-3 p-4 border-b border-light-border/10 dark:border-dark-border/10"
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
                class="w-full pl-10 pr-4 py-2.5 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50 transition-all"
              />
            </div>
            <select
              v-model="selectedCategory"
              class="px-4 py-2.5 rounded-lg surface-chip txt-primary focus:outline-none focus:ring-2 focus:ring-brand/50 transition-all cursor-pointer"
            >
              <option value="all">{{ $t('memories.categories.all') }}</option>
              <option v-for="cat in availableCategories" :key="cat.category" :value="cat.category">
                {{ $t(`memories.categories.${cat.category}`, cat.category) }} ({{ cat.count }})
              </option>
            </select>
            <button
              class="btn-primary px-4 py-2.5 rounded-lg flex items-center gap-2 font-medium"
              @click="openCreateDialog"
            >
              <Icon icon="mdi:plus" class="w-5 h-5" />
              <span>{{ $t('memories.actions.create') }}</span>
            </button>
          </div>

          <!-- Content -->
          <div class="flex-1 overflow-y-auto scroll-thin p-4">
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

            <div v-else-if="filteredMemories.length === 0" class="text-center py-12">
              <Icon icon="mdi:brain-off" class="w-16 h-16 mx-auto txt-secondary opacity-50 mb-4" />
              <h3 class="text-lg font-semibold txt-primary mb-2">
                {{ searchQuery ? $t('memories.search.noResults') : $t('memories.empty') }}
              </h3>
              <p class="txt-secondary text-sm">{{ $t('memories.emptyDesc') }}</p>
            </div>

            <div v-else class="space-y-3">
              <!-- Show limit notice if not filtering -->
              <div
                v-if="
                  !searchQuery && selectedCategory === 'all' && memoriesStore.memories.length > 10
                "
                class="surface-chip rounded-lg p-4 flex items-center justify-between"
              >
                <div class="flex items-center gap-3">
                  <Icon icon="mdi:information" class="w-5 h-5 txt-brand" />
                  <div>
                    <p class="text-sm txt-primary font-medium">
                      {{ $t('memories.showingRecent', { count: 10 }) }}
                    </p>
                    <p class="text-xs txt-secondary">
                      {{ $t('memories.totalCount', { count: memoriesStore.memories.length }) }}
                    </p>
                  </div>
                </div>
                <button class="btn-primary px-4 py-2 rounded-lg text-sm" @click="viewAllMemories">
                  {{ $t('memories.viewAll') }}
                </button>
              </div>

              <div
                v-for="memory in filteredMemories"
                :key="memory.id"
                class="surface-chip rounded-lg p-4 hover-surface transition-colors"
              >
                <div class="flex items-start justify-between gap-3 mb-2">
                  <div class="flex-1">
                    <div class="flex items-center gap-2 mb-1 flex-wrap">
                      <span class="text-sm font-medium txt-primary">{{ memory.key }}</span>
                      <span class="pill text-xs px-2 py-1">{{ memory.category }}</span>
                      <span class="text-xs txt-secondary"
                        >• {{ $t(`memories.source.${memory.source}`) }}</span
                      >
                    </div>
                    <p class="txt-primary text-sm">{{ memory.value }}</p>
                  </div>
                  <div class="flex items-center gap-1">
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
                <div class="flex items-center gap-4 text-xs txt-secondary">
                  <span>{{ $t('common.created') }}: {{ formatDate(memory.created) }}</span>
                  <span>{{ $t('common.updated') }}: {{ formatDate(memory.updated) }}</span>
                </div>
              </div>
            </div>
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
    />
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useMemoriesStore } from '@/stores/userMemories'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import type { UserMemory } from '@/services/api/userMemoriesApi'
import { getCategories } from '@/services/api/userMemoriesApi'
import MemoryFormDialog from '@/components/MemoryFormDialog.vue'

interface Props {
  isOpen: boolean
}

interface Emits {
  (e: 'close'): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

const { t } = useI18n()
const router = useRouter()
const memoriesStore = useMemoriesStore()
const { success, error } = useNotification()
const { confirm } = useDialog()

const loading = ref(false)
const searchQuery = ref('')
const selectedCategory = ref('all')
const showFormDialog = ref(false)
const editingMemory = ref<UserMemory | null>(null)
const availableCategories = ref<Array<{ category: string; count: number }>>([])

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

  // Limit to 10 most recent memories (unless searching/filtering)
  if (!searchQuery.value && selectedCategory.value === 'all') {
    return memories.slice(0, 10)
  }

  return memories
})

watch(
  () => props.isOpen,
  (isOpen) => {
    if (isOpen) {
      loadMemories()
    }
  }
)

async function loadMemories() {
  loading.value = true
  try {
    await memoriesStore.fetchMemories()
    availableCategories.value = await getCategories()
  } catch (err) {
    error(t('common.error'))
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

  try {
    await memoriesStore.removeMemory(memory.id)
    success(t('common.success'))
  } catch (err) {
    error(t('common.error'))
  }
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
    success(t('memories.memorySaved'))
    closeFormDialog()
  } catch (err) {
    error(t('common.error'))
  }
}

function formatDate(date: Date | number | string): string {
  const d = typeof date === 'string' || typeof date === 'number' ? new Date(date) : date
  return new Intl.DateTimeFormat('de-DE', {
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
