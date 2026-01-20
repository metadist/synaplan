<template>
  <MainLayout>
    <div class="min-h-screen bg-chat p-2 md:p-4 lg:p-8">
      <div class="max-w-7xl mx-auto h-full flex flex-col">
        <!-- Header -->
        <div
          class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 sm:gap-0 mb-4 md:mb-6"
        >
          <h1 class="text-2xl md:text-3xl font-bold txt-primary">
            {{ $t('pageTitles.memories') }}
          </h1>

          <!-- View Toggle -->
          <div class="flex items-center gap-2 surface-chip p-1 rounded-lg w-full sm:w-auto">
            <button
              class="flex-1 sm:flex-none px-3 md:px-4 py-2 rounded-md transition-colors text-sm nav-item"
              :class="viewMode === 'list' ? 'nav-item--active' : ''"
              @click="viewMode = 'list'"
            >
              <Icon icon="mdi:format-list-bulleted" class="w-4 h-4 md:w-5 md:h-5 inline mr-1" />
              <span class="hidden sm:inline">{{ $t('memories.listView.title') }}</span>
              <span class="sm:hidden">Liste</span>
            </button>
            <button
              class="flex-1 sm:flex-none px-3 md:px-4 py-2 rounded-md transition-colors text-sm nav-item"
              :class="viewMode === 'graph' ? 'nav-item--active' : ''"
              @click="viewMode = 'graph'"
            >
              <Icon icon="mdi:graph" class="w-4 h-4 md:w-5 md:h-5 inline mr-1" />
              <span class="hidden sm:inline">{{ $t('memories.graphView.title') }}</span>
              <span class="sm:hidden">Graph</span>
            </button>
          </div>
        </div>

        <!-- Views -->
        <div
          class="flex-1 surface-card rounded-xl p-3 md:p-6 overflow-hidden"
          style="min-height: 500px; max-height: calc(100vh - 200px)"
        >
          <!-- Loading State -->
          <div
            v-if="memoriesStore.loading"
            class="flex flex-col items-center justify-center h-full"
          >
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-brand-500 mb-4"></div>
            <p class="txt-secondary">{{ $t('memories.loading') }}</p>
          </div>

          <!-- Error State -->
          <div
            v-else-if="memoriesStore.error || isServiceUnavailable"
            class="flex flex-col items-center justify-center h-full max-w-lg mx-auto text-center"
          >
            <Icon icon="mdi:database-alert" class="w-20 h-20 text-orange-500 mb-4" />
            <h2 class="text-xl font-semibold txt-primary mb-2">
              {{ $t('memories.unavailable.title') }}
            </h2>
            <p class="txt-secondary mb-6">
              {{ $t('memories.unavailable.message') }}
            </p>
            <div class="surface-chip p-4 rounded-lg mb-4 text-left w-full">
              <h3 class="font-medium txt-primary mb-2">
                {{ $t('memories.unavailable.reason') }}
              </h3>
              <ul class="txt-secondary text-sm space-y-1 list-disc list-inside">
                <li>{{ $t('memories.unavailable.reason1') }}</li>
                <li>{{ $t('memories.unavailable.reason2') }}</li>
                <li>{{ $t('memories.unavailable.reason3') }}</li>
              </ul>
            </div>
            <button class="btn-primary" @click="retryConnection">
              <Icon icon="mdi:refresh" class="w-5 h-5 mr-2" />
              {{ $t('memories.unavailable.retry') }}
            </button>
          </div>

          <!-- Content Views -->
          <template v-else>
            <MemoryListView
              v-if="viewMode === 'list'"
              :memories="memoriesStore.memories"
              :available-categories="availableCategories"
              @edit="handleEdit"
              @delete="handleDelete"
              @bulk-delete="handleBulkDelete"
              @create="handleCreate"
            />
            <MemoryGraphView
              v-else
              class="w-full h-full"
              :memories="memoriesStore.memories"
              :available-categories="availableCategories"
              :selected-memory-id="selectedGraphMemory?.id ?? null"
              @select="selectedGraphMemory = $event"
            />
          </template>
        </div>

        <!-- Selected Memory Details (below graph, outside canvas) -->
        <div v-if="viewMode === 'graph' && selectedGraphMemory" class="mt-4">
          <MemorySelectionCard
            :memory="selectedGraphMemory"
            :category-color="
              graphCategoryColors[selectedGraphMemory.category] || graphCategoryColors.default
            "
            @close="selectedGraphMemory = null"
            @edit="handleEdit"
            @delete="handleDelete"
          />
        </div>
      </div>
    </div>

    <!-- Edit/Create Dialog -->
    <MemoryFormDialog
      :is-open="isFormDialogOpen"
      :memory="currentMemoryToEdit"
      :available-categories="availableCategories.map((c) => c.category)"
      @close="handleFormDialogClose"
      @save="handleSaveMemory"
    />
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import MemoryListView from '@/components/MemoryListView.vue'
import MemoryGraphView from '@/components/MemoryGraphView.vue'
import MemoryFormDialog from '@/components/MemoryFormDialog.vue'
import MemorySelectionCard from '@/components/memories/MemorySelectionCard.vue'
import { useMemoriesStore } from '@/stores/userMemories'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import { getCategories } from '@/services/api/userMemoriesApi'
import type {
  UserMemory,
  CreateMemoryRequest,
  UpdateMemoryRequest,
} from '@/services/api/userMemoriesApi'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const memoriesStore = useMemoriesStore()
const { success, error } = useNotification()
const { confirm } = useDialog()

const viewMode = ref<'list' | 'graph'>('list')
const availableCategories = ref<Array<{ category: string; count: number }>>([])
const isFormDialogOpen = ref(false)
const currentMemoryToEdit = ref<UserMemory | null>(null)
const isServiceUnavailable = ref(false)
const selectedGraphMemory = ref<UserMemory | null>(null)

const graphCategoryColors: Record<string, string> = {
  preferences: '#3b82f6',
  personal: '#10b981',
  work: '#f59e0b',
  projects: '#8b5cf6',
  default: '#6366f1',
}

onMounted(async () => {
  try {
    await memoriesStore.init()
    availableCategories.value = await getCategories()
    isServiceUnavailable.value = false

    // Check if we should open edit dialog from query params
    if (route.query.edit) {
      const memoryId = parseInt(route.query.edit as string)
      const memory = memoriesStore.memories.find((m) => m.id === memoryId)
      if (memory) {
        handleEdit(memory)
      }
    }

    // Check if we should highlight a memory from query params
    if (route.query.highlight) {
      const memoryId = parseInt(route.query.highlight as string)
      // Wait a bit for the view to render
      setTimeout(() => {
        const memoryElement = document.querySelector(`[data-memory-id="${memoryId}"]`)
        if (memoryElement) {
          memoryElement.scrollIntoView({ behavior: 'smooth', block: 'center' })
          // Add temporary highlight class
          memoryElement.classList.add('ring-2', 'ring-brand', 'bg-brand-alpha-light')
          setTimeout(() => {
            memoryElement.classList.remove('ring-2', 'ring-brand', 'bg-brand-alpha-light')
          }, 2000)
        }
      }, 300)
    }
  } catch (err) {
    // Check if it's a service unavailable error
    if (
      err instanceof Error &&
      (err.message.includes('503') || err.message.includes('unavailable'))
    ) {
      isServiceUnavailable.value = true
    }
  }
})

async function retryConnection() {
  isServiceUnavailable.value = false
  await memoriesStore.init()
  try {
    availableCategories.value = await getCategories()
    isServiceUnavailable.value = false
  } catch (err) {
    if (
      err instanceof Error &&
      (err.message.includes('503') || err.message.includes('unavailable'))
    ) {
      isServiceUnavailable.value = true
    }
  }
}

function handleCreate() {
  currentMemoryToEdit.value = null
  isFormDialogOpen.value = true
}

function handleEdit(memory: UserMemory) {
  currentMemoryToEdit.value = memory
  isFormDialogOpen.value = true
}

async function handleDelete(memory: UserMemory) {
  const confirmed = await confirm({
    title: t('memories.deleteConfirm.title'),
    message: t('memories.deleteConfirm.message'),
    confirmText: t('memories.deleteConfirm.confirm'),
    danger: true,
  })

  if (confirmed) {
    await memoriesStore.removeMemory(memory.id)
    if (!memoriesStore.error) {
      success(t('memories.deleteSuccess'))
      await loadMemories()
    } else {
      error(t('memories.deleteError'))
    }
  }
}

async function handleBulkDelete(memoryIds: number[]) {
  const confirmed = await confirm({
    title: t('memories.bulkDeleteConfirm.title'),
    message: t('memories.bulkDeleteConfirm.message', { count: memoryIds.length }),
    confirmText: t('memories.deleteConfirm.confirm'),
    danger: true,
  })

  if (confirmed) {
    for (const id of memoryIds) {
      await memoriesStore.removeMemory(id)
    }
    if (!memoriesStore.error) {
      success(t('memories.bulkDeleteSuccess', { count: memoryIds.length }))
      await loadMemories()
    } else {
      error(t('memories.bulkDeleteError'))
    }
  }
}

async function handleSaveMemory(memoryData: CreateMemoryRequest | UpdateMemoryRequest) {
  if (currentMemoryToEdit.value) {
    // For updates, we only need the value field
    await memoriesStore.editMemory(currentMemoryToEdit.value.id, {
      value: memoryData.value,
    } as UpdateMemoryRequest)
    if (!memoriesStore.error) {
      success(t('memories.editSuccess'))
    } else {
      error(t('memories.editError'))
    }
  } else {
    // For creates, we need category, key, and value
    await memoriesStore.addMemory(memoryData as CreateMemoryRequest)
    if (!memoriesStore.error) {
      success(t('memories.createSuccess'))
    } else {
      error(t('memories.createError'))
    }
  }
  isFormDialogOpen.value = false
  await loadMemories()
}

function handleFormDialogClose() {
  isFormDialogOpen.value = false
  currentMemoryToEdit.value = null
  // Remove edit query param if present
  if (route.query.edit) {
    router.replace({ query: {} })
  }
}

async function loadMemories() {
  await memoriesStore.fetchMemories()
  availableCategories.value = await getCategories()
}
</script>
