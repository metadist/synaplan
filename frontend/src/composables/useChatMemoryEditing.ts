import { provide, ref, type Ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { useMemoriesStore } from '@/stores/userMemories'
import { getCategories } from '@/services/api/userMemoriesApi'
import type { UserMemory } from '@/services/api/userMemoriesApi'
import { applyParsedMemoryActions, type ParsedMemoryAction } from '@/utils/applyParsedMemoryActions'
import { deleteUsedMemoryKey, editUsedMemoryKey } from '@/components/chat/focusUsedMemory'

interface EditorState {
  open: Ref<boolean>
  editing: Ref<UserMemory | null>
  categories: Ref<string[]>
}

/** Edit and delete for a memory the chat is showing, including Easy-mode saves. */
export function useChatMemoryEditing(dismissToast: (toastId: number) => void) {
  const { t } = useI18n()
  const { confirm } = useDialog()
  const toasts = useNotification()
  const memoriesStore = useMemoriesStore()
  const state: EditorState = {
    open: ref(false),
    editing: ref<UserMemory | null>(null),
    categories: ref<string[]>([]),
  }

  async function openMemoryEditor(memory: UserMemory): Promise<void> {
    await ensureCategories(state)
    state.editing.value = memory
    state.open.value = true
  }

  async function deleteUsedMemory(memory: UserMemory): Promise<void> {
    const confirmed = await confirm({
      title: t('memories.deleteConfirm.title'),
      message: t('memories.deleteConfirm.message'),
      confirmText: t('memories.deleteConfirm.confirm'),
      danger: true,
    })
    if (!confirmed) return
    try {
      await memoriesStore.removeMemory(memory.id)
    } catch {
      // The store shows the error.
    }
  }

  function closeMemoryEditDialog(): void {
    state.open.value = false
    state.editing.value = null
  }

  async function handleMemoryEditSave(memoryData: {
    category?: string
    key?: string
    value: string
  }): Promise<void> {
    const current = state.editing.value
    if (!current) return
    try {
      await memoriesStore.editMemory(
        current.id,
        { value: memoryData.value, category: memoryData.category, key: memoryData.key },
        { silent: false }
      )
      closeMemoryEditDialog()
    } catch {
      // The store shows the error.
    }
  }

  async function handleMemoryEasySave(actions: ParsedMemoryAction[]): Promise<void> {
    const { saved, failed } = await applyParsedMemoryActions(actions, memoriesStore)
    if (saved > 0 && failed === 0) {
      toasts.success(
        saved === 1 ? t('memories.editSuccess') : t('memories.multipleSuccess', { count: saved })
      )
    } else if (saved > 0) {
      toasts.warning(t('memories.multipleSuccess', { count: saved }))
    } else if (failed > 0) {
      toasts.error(t('memories.editError'))
    }
    closeMemoryEditDialog()
  }

  provide(editUsedMemoryKey, (memory) => {
    void openMemoryEditor(memory)
  })
  provide(deleteUsedMemoryKey, (memory) => {
    void deleteUsedMemory(memory)
  })

  return {
    isMemoryEditDialogOpen: state.open,
    editingMemory: state.editing,
    availableMemoryCategories: state.categories,
    openMemoryEditor,
    handleMemoryEdit(memory: UserMemory & { toastId: number }) {
      dismissToast(memory.toastId)
      void openMemoryEditor(memory)
    },
    deleteUsedMemory,
    closeMemoryEditDialog,
    handleMemoryEditSave,
    handleMemoryEasySave,
  }
}

async function ensureCategories(state: EditorState): Promise<void> {
  if (state.categories.value.length > 0) return
  try {
    const categories = await getCategories()
    state.categories.value = categories.map((category) => category.category)
  } catch {
    // The form still opens; category suggestions stay empty.
  }
}
