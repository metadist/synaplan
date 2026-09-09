<template>
  <section class="surface-card rounded-xl p-5 space-y-4" data-testid="section-builder-knowledge">
    <h2 class="txt-primary font-medium">{{ $t('assistants.knowledge') }}</h2>
    <p class="txt-secondary text-sm">{{ $t('assistants.ownFolderHint') }}</p>
    <div
      v-if="files.length === 0"
      class="txt-secondary text-sm"
      data-testid="state-knowledge-empty"
    >
      {{ $t('assistants.noFiles') }}
    </div>
    <ul v-else class="space-y-2">
      <li
        v-for="file in files"
        :key="file.messageId"
        class="flex items-center justify-between gap-2 text-sm txt-primary"
      >
        <span class="truncate">{{ file.fileName }}</span>
        <button
          type="button"
          class="btn-danger px-4 py-2.5 rounded-lg text-sm font-medium"
          :data-testid="`btn-delete-knowledge-file-${file.messageId}`"
          @click="onDeleteFile(file)"
        >
          {{ $t('assistants.deleteFile') }}
        </button>
      </li>
    </ul>
    <label
      class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium inline-flex items-center"
    >
      {{ $t('assistants.uploadFile') }}
      <input type="file" class="sr-only" data-testid="input-knowledge-file" @change="onUpload" />
    </label>
    <label class="flex items-start gap-2">
      <input
        type="checkbox"
        class="mt-1"
        :checked="includeUserFiles"
        data-testid="chk-include-user-files"
        @change="patchKnowledge('includeUserFiles', ($event.target as HTMLInputElement).checked)"
      />
      <span>
        <span class="txt-primary text-sm">{{ $t('assistants.includeUserFiles') }}</span>
        <span class="block txt-secondary text-sm">{{ $t('assistants.includeUserFilesHint') }}</span>
      </span>
    </label>

    <div class="space-y-2">
      <p class="txt-primary text-sm font-medium">{{ $t('assistants.sharedFolders') }}</p>
      <p class="txt-secondary text-sm">{{ $t('assistants.sharedFoldersHint') }}</p>
      <ul v-if="selectedFolders.length > 0" class="space-y-2">
        <li
          v-for="folder in selectedFolders"
          :key="folder.id"
          class="flex items-center justify-between gap-2 text-sm txt-primary"
        >
          <span class="truncate">{{ folder.label }}</span>
          <button
            type="button"
            class="btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium"
            :data-testid="`btn-remove-folder-${folder.id}`"
            @click="removeFolder(folder.id)"
          >
            {{ $t('assistants.removeFolder') }}
          </button>
        </li>
      </ul>
      <p v-else class="txt-secondary text-sm" data-testid="state-shared-folders-empty">
        {{ $t('assistants.noSharedFolders') }}
      </p>
      <label class="block">
        <span class="txt-secondary text-sm">{{ $t('assistants.addSharedFolder') }}</span>
        <select
          class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="select-shared-folder"
          :value="''"
          @change="addFolder(($event.target as HTMLSelectElement).value)"
        >
          <option value="">{{ $t('assistants.chooseFolder') }}</option>
          <option v-for="option in folderOptions" :key="option.id" :value="option.id">
            {{ option.label }}
          </option>
        </select>
      </label>
    </div>

    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.ragLimit') }}</span>
      <input
        :value="ragLimit"
        type="number"
        min="1"
        max="50"
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="input-rag-limit"
        @input="patchKnowledge('ragLimit', Number(($event.target as HTMLInputElement).value))"
      />
    </label>
    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.ragMinScore') }}</span>
      <input
        :value="ragMinScore"
        type="number"
        min="0"
        max="1"
        step="0.05"
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
        data-testid="input-rag-min-score"
        @input="patchKnowledge('ragMinScore', Number(($event.target as HTMLInputElement).value))"
      />
    </label>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { promptsApi, type PromptFile } from '@/services/api/promptsApi'
import { iamApi } from '@/services/api/iamApi'
import { emptyAgentDraft } from '@/services/api/agentsApi'
import { getFileGroups } from '@/services/filesService'
import { useAgentsStore } from '@/stores/agents'
import { useAuthStore } from '@/stores/auth'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'

type FolderOption = { id: string; label: string }

const store = useAgentsStore()
const auth = useAuthStore()
const { t } = useI18n()
const { confirm } = useDialog()
const { error, success } = useNotification()
const files = ref<PromptFile[]>([])
const allFolders = ref<FolderOption[]>([])

const topic = computed(() => {
  const slug = store.current?.slug
  return slug ? `agent:${slug}` : ''
})

const ragLimit = computed(() => store.current?.draft?.knowledge.ragLimit ?? 8)
const ragMinScore = computed(() => store.current?.draft?.knowledge.ragMinScore ?? 0.6)
const includeUserFiles = computed(() => store.current?.draft?.knowledge.includeUserFiles ?? false)
const selectedFolderIds = computed(() => store.current?.draft?.knowledge.folders ?? [])
const folderOptions = computed(() =>
  allFolders.value.filter((option) => !selectedFolderIds.value.includes(option.id))
)
const selectedFolders = computed(() =>
  selectedFolderIds.value.map((id) => ({
    id,
    label: allFolders.value.find((option) => option.id === id)?.label ?? folderLabel(id),
  }))
)

function folderLabel(id: string): string {
  const sep = id.indexOf(':')
  return sep >= 0 ? id.slice(sep + 1) : id
}

async function loadFiles(): Promise<void> {
  if (!topic.value) {
    files.value = []
    return
  }
  files.value = await promptsApi.getPromptFiles(topic.value)
}

async function onDeleteFile(file: PromptFile): Promise<void> {
  if (!topic.value) return
  const ok = await confirm({
    title: t('assistants.deleteFile'),
    message: t('assistants.deleteFileConfirm', { name: file.fileName }),
    danger: true,
  })
  if (!ok) return
  try {
    await promptsApi.deletePromptFile(topic.value, file.messageId)
    success(t('assistants.deleteFileSuccess'))
    await loadFiles()
  } catch {
    error(t('assistants.deleteFileFailed'))
  }
}

async function onUpload(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !topic.value) {
    return
  }
  await promptsApi.uploadPromptFile(topic.value, file)
  input.value = ''
  await loadFiles()
}

function patchKnowledge(
  key: 'ragLimit' | 'ragMinScore' | 'includeUserFiles' | 'folders',
  value: number | boolean | string[]
): void {
  if (!store.current) {
    return
  }
  const draft = store.current.draft ?? emptyAgentDraft()
  store.current.draft = {
    ...draft,
    knowledge: { ...draft.knowledge, ownFolder: true, [key]: value },
  }
  store.markDirty()
}

function addFolder(id: string): void {
  if (!id || selectedFolderIds.value.includes(id)) {
    return
  }
  patchKnowledge('folders', [...selectedFolderIds.value, id])
}

function removeFolder(id: string): void {
  patchKnowledge(
    'folders',
    selectedFolderIds.value.filter((folderId) => folderId !== id)
  )
}

async function loadFolderOptions(): Promise<void> {
  const ownerId = auth.user?.id
  const options: FolderOption[] = []
  try {
    const groups = await getFileGroups()
    for (const group of groups) {
      if (!group.name || group.name === 'DEFAULT' || group.name.startsWith('TASKPROMPT:')) {
        continue
      }
      if (ownerId) {
        options.push({ id: `${ownerId}:${group.name}`, label: group.name })
      }
    }
  } catch {
    // Own folders stay optional — shared list still works.
  }
  try {
    const shared = await iamApi.listSharedWithMe('knowledge_folder')
    for (const item of shared) {
      if (!['use', 'edit', 'manage'].includes(item.permission)) {
        continue
      }
      const label = item.ownerName
        ? t('assistants.sharedFolderOwnedBy', { name: item.name, owner: item.ownerName })
        : item.name
      options.push({ id: item.id, label })
    }
  } catch {
    // Sharing may be off.
  }
  const seen = new Set<string>()
  allFolders.value = options.filter((option) => {
    if (seen.has(option.id)) {
      return false
    }
    seen.add(option.id)
    return true
  })
}

onMounted(() => {
  void loadFiles()
  void loadFolderOptions()
})
</script>
