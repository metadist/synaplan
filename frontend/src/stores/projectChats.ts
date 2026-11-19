import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { projectsApi, type ProjectChatItem } from '@/services/api/projectsApi'

/** The chats of each project, and which project a chat is in. */
export const useProjectChatsStore = defineStore('projectChats', () => {
  const byProject = ref<Record<string, ProjectChatItem[]>>({})

  const projectIdOfChat = computed(() => {
    const index = new Map<number, string>()
    for (const [projectId, chats] of Object.entries(byProject.value)) {
      for (const chat of chats) index.set(chat.id, projectId)
    }
    return index
  })

  function chatsOf(projectId: string): ProjectChatItem[] {
    return byProject.value[projectId] ?? []
  }

  function set(projectId: string, chats: ProjectChatItem[]): void {
    byProject.value = { ...byProject.value, [projectId]: chats }
  }

  async function loadAll(projectIds: string[]): Promise<Record<string, ProjectChatItem[]>> {
    const entries = await Promise.all(
      projectIds.map(async (id) => [id, await projectsApi.listChats(id)] as const)
    )
    return Object.fromEntries(entries)
  }

  function replaceAll(next: Record<string, ProjectChatItem[]>): void {
    byProject.value = next
  }

  function forget(projectId: string): void {
    byProject.value = Object.fromEntries(
      Object.entries(byProject.value).filter(([id]) => id !== projectId)
    )
  }

  /** Re-read one project's chats: titles change after the first answer, chats get deleted elsewhere. */
  async function refresh(projectId: string): Promise<void> {
    set(projectId, await projectsApi.listChats(projectId))
  }

  async function create(projectId: string, title?: string): Promise<ProjectChatItem> {
    const chat = await projectsApi.createChat(projectId, title)
    set(projectId, [chat, ...chatsOf(projectId)])
    return chat
  }

  /** Move a chat into a project. A chat is in one project at a time. */
  async function move(projectId: string, chatId: number): Promise<void> {
    await projectsApi.joinChat(projectId, chatId)
    const touched = Object.keys(byProject.value).filter(
      (id) => id === projectId || chatsOf(id).some((c) => c.id === chatId)
    )
    await Promise.all(touched.map(refresh))
  }

  async function remove(projectId: string, chatId: number): Promise<void> {
    await projectsApi.leaveChat(projectId, chatId)
    set(
      projectId,
      chatsOf(projectId).filter((c) => c.id !== chatId)
    )
  }

  return {
    byProject,
    projectIdOfChat,
    chatsOf,
    set,
    loadAll,
    replaceAll,
    forget,
    refresh,
    create,
    move,
    remove,
  }
})
