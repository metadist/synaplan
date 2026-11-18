<template>
  <MainLayout>
    <div
      class="flex h-full flex-col overflow-y-auto bg-chat px-3 py-4 sm:p-4 md:p-8 scroll-thin"
      data-testid="page-project"
    >
      <div class="mx-auto w-full max-w-4xl">
    <p
      v-if="!store.available"
      class="rounded-xl surface-card px-4 py-8 text-center text-sm txt-secondary"
      data-testid="text-project-unavailable"
    >
      {{ $t('projects.unavailable') }}
    </p>
    <p
      v-else-if="notFound"
      class="rounded-xl surface-card px-4 py-8 text-center text-sm txt-secondary"
      data-testid="text-project-not-found"
    >
      {{ $t('projects.notFound') }}
    </p>
    <p
      v-else-if="loadFailed"
      class="rounded-xl surface-card px-4 py-8 text-center text-sm text-red-600 dark:text-red-400"
      role="alert"
    >
      {{ $t('projects.loadFailed') }}
    </p>

    <template v-else-if="project">
      <header class="mb-6 flex items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
          <input
            v-if="renaming"
            ref="nameInput"
            v-model="nameDraft"
            type="text"
            maxlength="120"
            class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-lg font-semibold focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :aria-label="$t('projects.nameLabel')"
            data-testid="input-project-name"
            @keydown.enter.prevent="saveName"
            @keydown.esc="renaming = false"
            @blur="saveName"
          />
          <h1
            v-else
            class="truncate text-2xl font-semibold txt-primary"
            data-testid="text-project-name"
          >
            {{ project.name }}
          </h1>
        </div>
        <div class="flex flex-shrink-0 items-center gap-1">
          <button
            type="button"
            class="icon-ghost inline-flex h-9 w-9 items-center justify-center rounded-lg"
            :aria-label="$t('projects.rename')"
            :title="$t('projects.rename')"
            data-testid="btn-project-rename"
            @click="startRename"
          >
            <PencilSquareIcon class="h-5 w-5" aria-hidden="true" />
          </button>
          <button
            type="button"
            class="icon-ghost inline-flex h-9 w-9 items-center justify-center rounded-lg"
            :aria-label="$t('projects.delete')"
            :title="$t('projects.delete')"
            data-testid="btn-project-delete"
            @click="deleteProject"
          >
            <TrashIcon class="h-5 w-5" aria-hidden="true" />
          </button>
        </div>
      </header>

      <TabNav v-model="tab" :tabs="tabs" :aria-label="project.name" testid="tabs-project" />

      <div class="mt-4">
        <ProjectChatsTab v-if="tab === 'chats'" :project-id="project.id" />
        <ProjectSourcesTab v-else-if="tab === 'sources'" :project-id="project.id" />
        <!-- Stays mounted so an unsaved draft survives a tab switch. -->
        <ProjectInstructionsTab v-show="tab === 'instructions'" :project="project" />
      </div>
    </template>

    <p v-else class="text-sm txt-secondary" role="status">{{ $t('common.loading') }}</p>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { PencilSquareIcon, TrashIcon } from '@heroicons/vue/24/outline'
import MainLayout from '@/components/MainLayout.vue'
import TabNav, { type TabNavItem } from '@/components/TabNav.vue'
import ProjectChatsTab from '@/components/projects/ProjectChatsTab.vue'
import ProjectSourcesTab from '@/components/projects/ProjectSourcesTab.vue'
import ProjectInstructionsTab from '@/components/projects/ProjectInstructionsTab.vue'
import { useProjectsStore } from '@/stores/projects'
import { useProjectChatsStore } from '@/stores/projectChats'
import { useChatsStore } from '@/stores/chats'
import { projectsApi, type DeleteChatsMode } from '@/services/api/projectsApi'
import { ApiError } from '@/services/api/httpClient'
import { featureNotConfigured } from '@/services/api/featureNotConfigured'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'

type TabId = 'chats' | 'sources' | 'instructions'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useProjectsStore()
const chatsStore = useProjectChatsStore()
const allChats = useChatsStore()
const { confirm, choose } = useDialog()
const { error: showError } = useNotification()

const tab = ref<TabId>('chats')
const notFound = ref(false)
const loadFailed = ref(false)
const renaming = ref(false)
const nameDraft = ref('')
const nameInput = ref<HTMLInputElement | null>(null)

const projectId = computed(() => String(route.params.id ?? ''))
const project = computed(() => store.projectById(projectId.value))

const tabs = computed<TabNavItem[]>(() => [
  { id: 'chats', label: t('projects.tabs.chats'), testid: 'tab-project-chats' },
  { id: 'sources', label: t('projects.tabs.sources'), testid: 'tab-project-sources' },
  {
    id: 'instructions',
    label: t('projects.tabs.instructions'),
    testid: 'tab-project-instructions',
  },
])

async function load(): Promise<void> {
  notFound.value = false
  loadFailed.value = false
  await store.loadOnce()
  if (!store.available || store.projectById(projectId.value)) return
  try {
    await projectsApi.get(projectId.value)
    await store.load()
    if (!store.projectById(projectId.value)) loadFailed.value = true
  } catch (error) {
    if (featureNotConfigured(error)) {
      store.available = false
    } else if (error instanceof ApiError && (error.status === 404 || error.status === 422)) {
      notFound.value = true
    } else {
      loadFailed.value = true
    }
  }
}

watch(projectId, () => void load(), { immediate: true })

async function startRename(): Promise<void> {
  nameDraft.value = project.value?.name ?? ''
  renaming.value = true
  await nextTick()
  nameInput.value?.focus()
  nameInput.value?.select()
}

async function saveName(): Promise<void> {
  if (!renaming.value) return
  renaming.value = false
  const name = nameDraft.value.trim()
  if (!project.value || name === '' || name === project.value.name) return
  try {
    await store.update(project.value.id, { name })
  } catch {
    showError(t('projects.renameFailed'))
  }
}

async function chooseDeleteMode(name: string, chatCount: number): Promise<DeleteChatsMode | null> {
  if (chatCount === 0) {
    const ok = await confirm({
      title: t('projects.deleteTitle', { name }),
      message: t('projects.deleteEmpty'),
      confirmText: t('projects.delete'),
      cancelText: t('common.cancel'),
      danger: true,
    })
    return ok ? 'keep' : null
  }
  const picked = await choose({
    title: t('projects.deleteTitle', { name }),
    message: t('projects.deleteWithChats', chatCount),
    confirmText: t('projects.deleteKeepChats'),
    extraText: t('projects.deleteAllChats'),
    cancelText: t('common.cancel'),
    danger: true,
  })
  if (picked === null) return null
  return picked === 'extra' ? 'delete' : 'keep'
}

async function deleteProject(): Promise<void> {
  const current = project.value
  if (!current) return
  const mode = await chooseDeleteMode(current.name, chatsStore.chatsOf(current.id).length)
  if (mode === null) return
  try {
    await store.remove(current.id, mode)
    // Chats deleted with the project must leave the Chats panel too.
    if (mode === 'delete') await allChats.loadChats()
    await router.push('/')
  } catch {
    showError(t('projects.deleteFailed'))
  }
}
</script>
