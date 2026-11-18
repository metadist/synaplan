import { defineStore } from 'pinia'
import { ref } from 'vue'
import {
  projectsApi,
  type DeleteChatsMode,
  type ProjectItem,
  type ProjectPatch,
} from '@/services/api/projectsApi'
import { featureNotConfigured } from '@/services/api/featureNotConfigured'
import { useProjectChatsStore } from '@/stores/projectChats'

const newestFirst = (list: ProjectItem[]): ProjectItem[] =>
  [...list].sort((a, b) => Date.parse(b.updatedAt) - Date.parse(a.updatedAt))

/**
 * Projects of the signed-in person. `available` is false when the module is
 * off: the project routes answer 404 `feature_not_configured`, and the UI
 * treats that as "no projects here", not as an error.
 */
export const useProjectsStore = defineStore('projects', () => {
  const chats = useProjectChatsStore()
  const projects = ref<ProjectItem[]>([])
  const available = ref(true)
  const loaded = ref(false)
  const loading = ref(false)
  const loadFailed = ref(false)
  let loadSeq = 0

  function projectById(projectId: string): ProjectItem | undefined {
    return projects.value.find((p) => p.id === projectId)
  }

  function projectOfChat(chatId: number | null | undefined): ProjectItem | undefined {
    const projectId = chatId == null ? undefined : chats.projectIdOfChat.get(chatId)
    return projectId ? projectById(projectId) : undefined
  }

  function upsert(project: ProjectItem): void {
    projects.value = newestFirst([project, ...projects.value.filter((p) => p.id !== project.id)])
  }

  async function load(): Promise<void> {
    const seq = ++loadSeq
    loading.value = true
    loadFailed.value = false
    try {
      const list = newestFirst(await projectsApi.list())
      const byProject = await chats.loadAll(list.map((p) => p.id))
      if (seq !== loadSeq) return
      projects.value = list
      chats.replaceAll(byProject)
      available.value = true
    } catch (error) {
      if (seq !== loadSeq) return
      if (featureNotConfigured(error)) {
        available.value = false
        projects.value = []
        chats.replaceAll({})
      } else {
        loadFailed.value = true
      }
    } finally {
      if (seq === loadSeq) {
        loading.value = false
        loaded.value = true
      }
    }
  }

  async function loadOnce(): Promise<void> {
    if (!loaded.value && !loading.value) await load()
  }

  async function create(name: string, description?: string): Promise<ProjectItem> {
    const project = await projectsApi.create(name, description)
    upsert(project)
    chats.set(project.id, [])
    return project
  }

  async function update(projectId: string, patch: ProjectPatch): Promise<ProjectItem> {
    const project = await projectsApi.update(projectId, patch)
    upsert(project)
    return project
  }

  async function remove(projectId: string, mode: DeleteChatsMode): Promise<void> {
    await projectsApi.remove(projectId, mode)
    projects.value = projects.value.filter((p) => p.id !== projectId)
    chats.forget(projectId)
  }

  function reset(): void {
    loadSeq++
    projects.value = []
    chats.replaceAll({})
    available.value = true
    loaded.value = false
    loading.value = false
    loadFailed.value = false
  }

  return {
    projects,
    available,
    loaded,
    loading,
    loadFailed,
    projectById,
    projectOfChat,
    load,
    loadOnce,
    create,
    update,
    remove,
    reset,
  }
})
