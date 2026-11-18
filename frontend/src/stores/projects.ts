import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  projectsApi,
  type DeleteChatsMode,
  type ProjectChatItem,
  type ProjectItem,
  type ProjectPatch,
} from '@/services/api/projectsApi'
import { featureNotConfigured } from '@/services/api/featureNotConfigured'

/**
 * Projects of the signed-in person and the chats that belong to each one.
 *
 * `available` is false when the module is off: the project routes answer 404
 * `feature_not_configured`, and the UI treats that as "no projects here", not
 * as an error.
 */
export const useProjectsStore = defineStore('projects', () => {
  const projects = ref<ProjectItem[]>([])
  const chatsByProject = ref<Record<string, ProjectChatItem[]>>({})
  const available = ref(true)
  const loaded = ref(false)
  const loading = ref(false)
  const loadFailed = ref(false)
  let loadSeq = 0

  const chatProjectIndex = computed(() => {
    const index = new Map<number, string>()
    for (const [projectId, chats] of Object.entries(chatsByProject.value)) {
      for (const chat of chats) {
        index.set(chat.id, projectId)
      }
    }
    return index
  })

  function projectById(projectId: string): ProjectItem | undefined {
    return projects.value.find((p) => p.id === projectId)
  }

  function projectOfChat(chatId: number | null | undefined): ProjectItem | undefined {
    if (chatId == null) return undefined
    const projectId = chatProjectIndex.value.get(chatId)
    return projectId ? projectById(projectId) : undefined
  }

  function sortProjects(): void {
    projects.value = [...projects.value].sort(
      (a, b) => Date.parse(b.updatedAt) - Date.parse(a.updatedAt)
    )
  }

  function upsert(project: ProjectItem): void {
    const next = projects.value.filter((p) => p.id !== project.id)
    projects.value = [project, ...next]
    sortProjects()
  }

  async function load(): Promise<void> {
    const seq = ++loadSeq
    loading.value = true
    loadFailed.value = false
    try {
      const list = await projectsApi.list()
      if (seq !== loadSeq) return
      projects.value = list
      sortProjects()
      available.value = true
      const entries = await Promise.all(
        list.map(async (p) => [p.id, await projectsApi.listChats(p.id)] as const)
      )
      if (seq !== loadSeq) return
      chatsByProject.value = Object.fromEntries(entries)
    } catch (error) {
      if (seq !== loadSeq) return
      if (featureNotConfigured(error)) {
        available.value = false
        projects.value = []
        chatsByProject.value = {}
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
    if (!loaded.value && !loading.value) {
      await load()
    }
  }

  async function create(name: string, description?: string): Promise<ProjectItem> {
    const project = await projectsApi.create(name, description)
    upsert(project)
    chatsByProject.value = { ...chatsByProject.value, [project.id]: [] }
    return project
  }

  async function update(projectId: string, patch: ProjectPatch): Promise<ProjectItem> {
    const project = await projectsApi.update(projectId, patch)
    upsert(project)
    return project
  }

  async function remove(projectId: string, chats: DeleteChatsMode): Promise<void> {
    await projectsApi.remove(projectId, chats)
    projects.value = projects.value.filter((p) => p.id !== projectId)
    const { [projectId]: _gone, ...rest } = chatsByProject.value
    chatsByProject.value = rest
  }

  async function refreshChats(projectId: string): Promise<void> {
    const chats = await projectsApi.listChats(projectId)
    chatsByProject.value = { ...chatsByProject.value, [projectId]: chats }
  }

  async function createChat(projectId: string, title?: string): Promise<ProjectChatItem> {
    const chat = await projectsApi.createChat(projectId, title)
    chatsByProject.value = {
      ...chatsByProject.value,
      [projectId]: [chat, ...(chatsByProject.value[projectId] ?? [])],
    }
    return chat
  }

  /** Move a chat into a project. A chat is in one project at a time. */
  async function moveChat(projectId: string, chatId: number): Promise<void> {
    await projectsApi.joinChat(projectId, chatId)
    await Promise.all(
      Object.keys(chatsByProject.value)
        .filter((id) => id === projectId || chatsByProject.value[id].some((c) => c.id === chatId))
        .map((id) => refreshChats(id))
    )
  }

  async function removeChat(projectId: string, chatId: number): Promise<void> {
    await projectsApi.leaveChat(projectId, chatId)
    chatsByProject.value = {
      ...chatsByProject.value,
      [projectId]: (chatsByProject.value[projectId] ?? []).filter((c) => c.id !== chatId),
    }
  }

  function reset(): void {
    loadSeq++
    projects.value = []
    chatsByProject.value = {}
    available.value = true
    loaded.value = false
    loading.value = false
    loadFailed.value = false
  }

  return {
    projects,
    chatsByProject,
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
    refreshChats,
    createChat,
    moveChat,
    removeChat,
    reset,
  }
})
