<template>
  <MainLayout>
    <div class="min-h-screen bg-chat p-2 md:p-4 lg:p-8 relative overflow-x-hidden">
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
              <span class="sm:hidden">2D</span>
            </button>
            <button
              v-if="is3dSupported"
              class="flex-1 sm:flex-none px-3 md:px-4 py-2 rounded-md transition-colors text-sm nav-item"
              :class="viewMode === 'graph3d' ? 'nav-item--active' : ''"
              @click="viewMode = 'graph3d'"
            >
              <Icon icon="mdi:cube-outline" class="w-4 h-4 md:w-5 md:h-5 inline mr-1" />
              <span class="hidden sm:inline">{{ $t('memories.graph3dView.title') }}</span>
              <span class="sm:hidden">3D</span>
            </button>
          </div>
        </div>

        <!-- Views -->
        <div
          class="flex-1 surface-card rounded-xl p-3 md:p-6"
          :class="viewMode === 'list' ? 'overflow-visible' : 'overflow-hidden'"
          :style="{
            minHeight: '500px',
            maxHeight: viewMode === 'list' ? 'none' : 'calc(100vh - 200px)',
          }"
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
              v-else-if="viewMode === 'graph'"
              class="w-full h-full"
              :memories="memoriesStore.memories"
              :available-categories="availableCategories"
              :selected-memory-id="selectedGraphMemory?.id ?? null"
              @select="selectedGraphMemory = $event"
              @edit="handleEdit"
              @delete="handleDelete"
            />
            <MemoryGraph3DView
              v-else-if="viewMode === 'graph3d' && is3dSupported"
              class="w-full h-full"
              :memories="memoriesStore.memories"
              :selected-memory="selectedGraphMemory"
              @select-memory="selectedGraphMemory = $event"
              @edit-memory="handleEdit"
              @delete-memory="handleDelete"
            />
          </template>
        </div>

        <!-- Selected Memory Details (below graph, outside canvas) -->
        <div
          v-if="(viewMode === 'graph' || viewMode === 'graph3d') && selectedGraphMemory"
          class="mt-4"
        >
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

      <!-- Fullscreen Overlay wenn Memories für User deaktiviert sind -->
      <Teleport to="body">
        <div
          v-if="!memoriesEnabledForUser"
          class="fixed inset-0 z-[9999] flex items-center justify-center p-4 bg-black/70 backdrop-blur-md"
          @click.self="router.push('/profile')"
        >
          <div
            class="surface-elevated max-w-md w-full p-8 rounded-2xl shadow-2xl animate-in fade-in zoom-in-95 duration-300"
          >
            <!-- Icon Header -->
            <div class="flex justify-center mb-6">
              <div
                class="w-20 h-20 rounded-full flex items-center justify-center"
                style="background: linear-gradient(135deg, #f97316 0%, #fb923c 100%)"
              >
                <Icon icon="mdi:lock-alert" class="w-12 h-12 text-white" />
              </div>
            </div>

            <!-- Title & Message -->
            <div class="text-center mb-6">
              <h2 class="text-2xl font-bold txt-primary mb-3">
                {{ $t('memories.userDisabled.title') }}
              </h2>
              <p class="txt-secondary text-base leading-relaxed">
                {{ $t('memories.userDisabled.message') }}
              </p>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col gap-3">
              <button
                class="w-full btn-primary py-3.5 rounded-xl font-semibold text-base flex items-center justify-center gap-2 shadow-lg hover:shadow-xl transition-shadow"
                @click="enableMemoriesForUser"
              >
                <Icon icon="mdi:check-circle" class="w-6 h-6" />
                {{ $t('memories.userDisabled.enable') }}
              </button>
              <button
                class="w-full surface-chip py-3 rounded-xl font-medium txt-secondary hover:txt-primary transition-colors flex items-center justify-center gap-2"
                @click="router.push('/profile?highlight=memories')"
              >
                <Icon icon="mdi:cog" class="w-5 h-5" />
                {{ $t('pageTitles.profile') }}
              </button>
            </div>
          </div>
        </div>
      </Teleport>
    </div>

    <!-- Edit/Create Dialog -->
    <MemoryFormDialog
      :is-open="isFormDialogOpen"
      :memory="currentMemoryToEdit"
      :available-categories="availableCategories.map((c) => c.category)"
      @close="handleFormDialogClose"
      @save="handleSaveMemory"
      @save-multiple="handleSaveMultiple"
    />
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, onMounted, computed, watch, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import MemoryListView from '@/components/MemoryListView.vue'
import MemoryGraphView from '@/components/MemoryGraphView.vue'
import MemoryGraph3DView from '@/components/MemoryGraph3DView.vue'
import MemoryFormDialog from '@/components/MemoryFormDialog.vue'
import MemorySelectionCard from '@/components/memories/MemorySelectionCard.vue'
import { useMemoriesStore } from '@/stores/userMemories'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import { getCategories } from '@/services/api/userMemoriesApi'
import { profileApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type {
  UserMemory,
  CreateMemoryRequest,
  UpdateMemoryRequest,
} from '@/services/api/userMemoriesApi'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const memoriesStore = useMemoriesStore()
const { success, error, warning } = useNotification()
const { confirm } = useDialog()

const viewMode = ref<'list' | 'graph' | 'graph3d'>('list')
const is3dSupported = ref(true)
let viewportMql: MediaQueryList | null = null
const handleViewportChange = () => {
  update3dSupport()
}
const handleFullscreenChange = () => {
  update3dSupport()
}

const availableCategories = ref<Array<{ category: string; count: number }>>([])
const isFormDialogOpen = ref(false)
const currentMemoryToEdit = ref<UserMemory | null>(null)
const isServiceUnavailable = ref(false)
const selectedGraphMemory = ref<UserMemory | null>(null)

const memoriesEnabledForUser = computed(() => authStore.user?.memoriesEnabled !== false)

const graphCategoryColors: Record<string, string> = {
  preferences: '#3b82f6',
  personal: '#10b981',
  work: '#f59e0b',
  projects: '#8b5cf6',
  default: '#6366f1',
}

function update3dSupport() {
  const isCoarse = window.matchMedia('(pointer: coarse)').matches
  const isSmall = window.matchMedia('(max-width: 768px)').matches
  is3dSupported.value = !(isCoarse || isSmall)
  if (!is3dSupported.value && viewMode.value === 'graph3d') {
    viewMode.value = 'graph'
  }
}

onMounted(async () => {
  viewportMql = window.matchMedia('(max-width: 768px)')
  viewportMql.addEventListener?.('change', handleViewportChange)
  document.addEventListener('fullscreenchange', handleFullscreenChange)
  update3dSupport()

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

onBeforeUnmount(() => {
  if (viewportMql) {
    viewportMql.removeEventListener?.('change', handleViewportChange)
  }
  document.removeEventListener('fullscreenchange', handleFullscreenChange)
})

// Keep selection consistent when memories list changes (e.g. after delete)
watch(
  () => memoriesStore.memories,
  (list) => {
    if (!selectedGraphMemory.value) return
    const exists = list.some((m) => m.id === selectedGraphMemory.value!.id)
    if (!exists) {
      selectedGraphMemory.value = null
    }
  }
)

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
      if (selectedGraphMemory.value?.id === memory.id) {
        selectedGraphMemory.value = null
      }
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
  try {
    // Check if this is an update (either from Advanced mode edit or Easy mode AI suggestion)
    const memoryDataAny = memoryData as Record<string, unknown>
    const updateId = currentMemoryToEdit.value?.id || (memoryDataAny.id as number | undefined)

    if (updateId) {
      // For updates, we need value (and optionally category/key)
      await memoriesStore.editMemory(updateId, {
        value: memoryData.value,
        category: 'category' in memoryData ? memoryData.category : undefined,
        key: 'key' in memoryData ? memoryData.key : undefined,
      } as UpdateMemoryRequest)
    } else {
      // For creates, we need category, key, and value
      await memoriesStore.addMemory(memoryData as CreateMemoryRequest)
    }
    // Store handles notifications - don't show duplicate!
    isFormDialogOpen.value = false
    await loadMemories()
  } catch {
    // Store already shows error notification
  }
}

interface ParsedAction {
  action: 'create' | 'update' | 'delete'
  memory?: { category: string; key: string; value: string }
  existingId?: number
  reason?: string
}

async function handleSaveMultiple(actions: ParsedAction[]) {
  let successCount = 0
  let errorCount = 0
  const errors: string[] = []

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
      errors.push(err instanceof Error ? err.message : 'Unknown error')
      // Continue with other actions even if one fails
    }
  }

  isFormDialogOpen.value = false
  await loadMemories()

  // Show single notification for the batch operation
  if (successCount > 0 && errorCount === 0) {
    if (successCount === 1) {
      success(t('memories.createSuccess'))
    } else {
      success(t('memories.multipleSuccess', { count: successCount }))
    }
  } else if (successCount > 0 && errorCount > 0) {
    // Show partial success with error details
    const errorDetails = errors.length > 0 ? `: ${errors[0]}` : ''
    warning(
      t('memories.multipleSuccess', { count: successCount }) +
        ` (${errorCount} ${t('common.error').toLowerCase()}${errorDetails})`
    )
  } else if (errorCount > 0) {
    error(t('memories.createError'))
  }
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

async function enableMemoriesForUser() {
  try {
    await profileApi.updateProfile({ memoriesEnabled: true })
    await authStore.refreshUser()
    success(t('memories.userDisabled.enable'))
  } catch (err: any) {
    error(err.message || 'Failed to enable memories')
  }
}
</script>
