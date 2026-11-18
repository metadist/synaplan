import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  DeleteApiProjectsChatsLeaveResponseSchema,
  DeleteApiProjectsDeleteResponseSchema,
  DeleteApiProjectsSourcesRemoveResponseSchema,
  GetApiProjectsChatsListResponseSchema,
  GetApiProjectsGetResponseSchema,
  GetApiProjectsListResponseSchema,
  GetApiProjectsSourcesListResponseSchema,
  PatchApiProjectsUpdateResponseSchema,
  PostApiProjectsChatsCreateResponseSchema,
  PostApiProjectsCreateResponseSchema,
  PostApiProjectsSourcesAddResponseSchema,
  PostApiProjectsSourcesRefreshResponseSchema,
  Project,
  ProjectChat,
  ProjectSource,
  PutApiProjectsChatsJoinResponseSchema,
} from '@/generated/api-schemas'

export type ProjectItem = z.infer<typeof Project>
export type ProjectChatItem = z.infer<typeof ProjectChat>
export type ProjectSourceItem = z.infer<typeof ProjectSource>
export type ProjectSourceState = ProjectSourceItem['state']
export type ProjectSourceKind = ProjectSourceItem['kind']

export type ProjectSourceInput =
  | { type: 'link'; url: string }
  | { type: 'text'; title: string; text: string }

export interface ProjectPatch {
  name?: string
  description?: string | null
  instructions?: string | null
}

export interface AddSourcesResult {
  sources: ProjectSourceItem[]
  errors: { filename: string; error: string }[]
}

export type DeleteChatsMode = 'keep' | 'delete'

const BASE = '/api/v1/projects'

function path(projectId: string, rest = ''): string {
  return `${BASE}/${encodeURIComponent(projectId)}${rest}`
}

export const projectsApi = {
  async list(): Promise<ProjectItem[]> {
    const data = await httpClient(BASE, { schema: GetApiProjectsListResponseSchema })
    return data.projects
  },

  async create(name: string, description?: string): Promise<ProjectItem> {
    const data = await httpClient(BASE, {
      method: 'POST',
      body: JSON.stringify(description ? { name, description } : { name }),
      schema: PostApiProjectsCreateResponseSchema,
    })
    return data.project
  },

  async get(projectId: string): Promise<ProjectItem> {
    const data = await httpClient(path(projectId), { schema: GetApiProjectsGetResponseSchema })
    return data.project
  },

  async update(projectId: string, patch: ProjectPatch): Promise<ProjectItem> {
    const data = await httpClient(path(projectId), {
      method: 'PATCH',
      body: JSON.stringify(patch),
      schema: PatchApiProjectsUpdateResponseSchema,
    })
    return data.project
  },

  async remove(projectId: string, chats: DeleteChatsMode): Promise<void> {
    await httpClient(`${path(projectId)}?chats=${chats}`, {
      method: 'DELETE',
      schema: DeleteApiProjectsDeleteResponseSchema,
    })
  },

  async listSources(projectId: string): Promise<ProjectSourceItem[]> {
    const data = await httpClient(path(projectId, '/sources'), {
      schema: GetApiProjectsSourcesListResponseSchema,
    })
    return data.sources
  },

  async addFiles(projectId: string, files: File[]): Promise<AddSourcesResult> {
    const form = new FormData()
    for (const file of files) {
      form.append('files[]', file)
    }
    const data = await httpClient(path(projectId, '/sources'), {
      method: 'POST',
      body: form,
      schema: PostApiProjectsSourcesAddResponseSchema,
    })
    return {
      sources: data.sources,
      errors: data.errors.map((e) => ({ filename: e.filename ?? '', error: e.error ?? '' })),
    }
  },

  async addSource(projectId: string, input: ProjectSourceInput): Promise<AddSourcesResult> {
    const data = await httpClient(path(projectId, '/sources'), {
      method: 'POST',
      body: JSON.stringify(input),
      schema: PostApiProjectsSourcesAddResponseSchema,
    })
    return {
      sources: data.sources,
      errors: data.errors.map((e) => ({ filename: e.filename ?? '', error: e.error ?? '' })),
    }
  },

  async refreshSource(projectId: string, fileId: number): Promise<ProjectSourceItem> {
    const data = await httpClient(path(projectId, `/sources/${fileId}/refresh`), {
      method: 'POST',
      schema: PostApiProjectsSourcesRefreshResponseSchema,
    })
    return data.source
  },

  async removeSource(projectId: string, fileId: number): Promise<void> {
    await httpClient(path(projectId, `/sources/${fileId}`), {
      method: 'DELETE',
      schema: DeleteApiProjectsSourcesRemoveResponseSchema,
    })
  },

  async listChats(projectId: string): Promise<ProjectChatItem[]> {
    const data = await httpClient(path(projectId, '/chats'), {
      schema: GetApiProjectsChatsListResponseSchema,
    })
    return data.chats
  },

  async createChat(projectId: string, title?: string): Promise<ProjectChatItem> {
    const data = await httpClient(path(projectId, '/chats'), {
      method: 'POST',
      body: JSON.stringify(title ? { title } : {}),
      schema: PostApiProjectsChatsCreateResponseSchema,
    })
    return data.chat
  },

  async joinChat(projectId: string, chatId: number): Promise<void> {
    await httpClient(path(projectId, `/chats/${chatId}`), {
      method: 'PUT',
      schema: PutApiProjectsChatsJoinResponseSchema,
    })
  },

  async leaveChat(projectId: string, chatId: number): Promise<void> {
    await httpClient(path(projectId, `/chats/${chatId}`), {
      method: 'DELETE',
      schema: DeleteApiProjectsChatsLeaveResponseSchema,
    })
  },
}
