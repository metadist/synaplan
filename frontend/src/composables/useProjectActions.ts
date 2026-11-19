import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useProjectsStore } from '@/stores/projects'
import { useProjectChatsStore } from '@/stores/projectChats'
import { useChatsStore } from '@/stores/chats'
import type { DeleteChatsMode, ProjectItem } from '@/services/api/projectsApi'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'

const NAME_MAX = 120

/** Actions on one project, shared by the project page and the sidebar menu. */
export function useProjectActions() {
  const { t } = useI18n()
  const router = useRouter()
  const store = useProjectsStore()
  const chatsStore = useProjectChatsStore()
  const allChats = useChatsStore()
  const { confirm, choose, prompt } = useDialog()
  const { error: showError } = useNotification()

  async function chooseDeleteMode(project: ProjectItem): Promise<DeleteChatsMode | null> {
    const chatCount = chatsStore.chatsOf(project.id).length
    const title = t('projects.deleteTitle', { name: project.name })
    if (chatCount === 0) {
      const ok = await confirm({
        title,
        message: t('projects.deleteEmpty'),
        confirmText: t('projects.delete'),
        cancelText: t('common.cancel'),
        danger: true,
      })
      return ok ? 'keep' : null
    }
    const picked = await choose({
      title,
      message: t('projects.deleteWithChats', chatCount),
      confirmText: t('projects.deleteKeepChats'),
      extraText: t('projects.deleteAllChats'),
      cancelText: t('common.cancel'),
      // Keeping the chats is the safe default; only "delete chats too" is red.
      danger: false,
    })
    if (picked === null) return null
    return picked === 'extra' ? 'delete' : 'keep'
  }

  /** Asks keep or delete for the chats, deletes, and leaves the page of a deleted project. */
  async function deleteProject(project: ProjectItem): Promise<void> {
    const mode = await chooseDeleteMode(project)
    if (mode === null) return
    try {
      await store.remove(project.id, mode)
      // Chats deleted with the project must leave the Chats panel too.
      if (mode === 'delete') await allChats.loadChats()
      if (router.currentRoute.value.params.id === project.id) await router.push('/')
    } catch {
      showError(t('projects.deleteFailed'))
    }
  }

  async function renameProject(project: ProjectItem): Promise<void> {
    const name = await prompt({
      title: t('projects.rename'),
      message: t('projects.renameMessage'),
      defaultValue: project.name,
      placeholder: t('projects.namePlaceholder'),
      confirmText: t('common.rename'),
      cancelText: t('common.cancel'),
    })
    const trimmed = name?.trim().slice(0, NAME_MAX)
    if (!trimmed || trimmed === project.name) return
    try {
      await store.update(project.id, { name: trimmed })
    } catch {
      showError(t('projects.renameFailed'))
    }
  }

  async function togglePin(project: ProjectItem): Promise<void> {
    try {
      await store.update(project.id, { pinned: !project.pinned })
    } catch {
      showError(t('projects.pinFailed'))
    }
  }

  /** The chat view reads `?chat=` after it has loaded the list, so the new chat is found. */
  async function newProjectChat(project: ProjectItem): Promise<void> {
    try {
      const chat = await chatsStore.create(project.id)
      await router.push({ path: '/', query: { chat: String(chat.id) } })
    } catch {
      showError(t('projects.chats.createFailed'))
    }
  }

  return { deleteProject, renameProject, newProjectChat, togglePin }
}
