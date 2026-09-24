import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  ListAdminGroupsResponseSchema,
  CreateAdminGroupResponseSchema,
  UpdateAdminGroupResponseSchema,
  DeleteAdminGroupResponseSchema,
  ListAdminGroupMembersResponseSchema,
  ListAdminGroupSharesResponseSchema,
  PutAdminGroupMemberResponseSchema,
  DeleteAdminGroupMemberResponseSchema,
  CountMyGrantsToGroupResponseSchema,
  LeaveMyGroupResponseSchema,
  ListMyGroupsResponseSchema,
  ListSharesResponseSchema,
  GrantShareResponseSchema,
  RevokeShareResponseSchema,
  SearchIamSubjectsResponseSchema,
  ListSharedWithMeResponseSchema,
  CountUnseenSharedResponseSchema,
  MarkSharedSeenResponseSchema,
  MarkSharedItemSeenResponseSchema,
  ContinueSharedChatResponseSchema,
  ListAdminAuditResponseSchema,
  GetAdminGroupConfigResponseSchema,
  GetAdminConfigLocksResponseSchema,
  PatchAdminConfigLocksResponseSchema,
} from '@/generated/api-schemas'

export type IamAuditEntry = NonNullable<
  z.infer<typeof ListAdminAuditResponseSchema>['entries']
>[number]

export type IamGroup = NonNullable<z.infer<typeof ListAdminGroupsResponseSchema>['groups']>[number]
export type IamMyGroup = NonNullable<z.infer<typeof ListMyGroupsResponseSchema>['groups']>[number]
export type IamGroupMember = NonNullable<
  z.infer<typeof ListAdminGroupMembersResponseSchema>['members']
>[number]
export type IamGroupShare = NonNullable<
  z.infer<typeof ListAdminGroupSharesResponseSchema>['shares']
>[number]

export type IamShare = NonNullable<z.infer<typeof ListSharesResponseSchema>['shares']>[number]
export type IamSubject = NonNullable<
  z.infer<typeof SearchIamSubjectsResponseSchema>['subjects']
>[number]
export type IamSharedItem = NonNullable<
  z.infer<typeof ListSharedWithMeResponseSchema>['items']
>[number]

export type IamGroupConfigSetting = NonNullable<
  z.infer<typeof GetAdminGroupConfigResponseSchema>['settings']
>[string]

/**
 * PHP json_encode turns an empty assoc array into `[]`. Zod `z.record()`
 * rejects that, which is what made People → Policies toast "could not be loaded"
 * on a healthy 200. Coerce only that empty list; leave other invalid shapes
 * for the generated schema to reject.
 */
function objectMap(value: unknown): unknown {
  if (Array.isArray(value) && value.length === 0) {
    return {}
  }
  return value
}

export const GroupConfigResponseSchema = z.preprocess((raw) => {
  if (raw === null || typeof raw !== 'object' || Array.isArray(raw)) {
    return raw
  }
  const data = raw as Record<string, unknown>
  return {
    ...data,
    settings: objectMap(data.settings),
    conflicts: objectMap(data.conflicts),
  }
}, GetAdminGroupConfigResponseSchema)

function readGroupConfig(data: z.infer<typeof GetAdminGroupConfigResponseSchema>): {
  settings: Record<string, IamGroupConfigSetting>
  conflicts: Record<string, string[]>
} {
  return {
    settings: (data.settings ?? {}) as Record<string, IamGroupConfigSetting>,
    conflicts: (data.conflicts ?? {}) as Record<string, string[]>,
  }
}

export const iamApi = {
  async listAdminGroups(): Promise<IamGroup[]> {
    const data = await httpClient('/api/v1/admin/groups', {
      method: 'GET',
      schema: ListAdminGroupsResponseSchema,
    })
    return data.groups ?? []
  },

  async createGroup(name: string, description = ''): Promise<IamGroup> {
    const data = await httpClient('/api/v1/admin/groups', {
      method: 'POST',
      body: JSON.stringify({ name, description }),
      schema: CreateAdminGroupResponseSchema,
    })
    return data.group
  },

  async updateGroup(
    id: number,
    payload: { name?: string; description?: string }
  ): Promise<IamGroup> {
    const data = await httpClient(`/api/v1/admin/groups/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
      schema: UpdateAdminGroupResponseSchema,
    })
    return data.group
  },

  async deleteGroup(id: number): Promise<void> {
    await httpClient(`/api/v1/admin/groups/${id}`, {
      method: 'DELETE',
      schema: DeleteAdminGroupResponseSchema,
    })
  },

  async listMembers(groupId: number): Promise<IamGroupMember[]> {
    const data = await httpClient(`/api/v1/admin/groups/${groupId}/members`, {
      method: 'GET',
      schema: ListAdminGroupMembersResponseSchema,
    })
    return data.members ?? []
  },

  async listGroupShares(groupId: number): Promise<IamGroupShare[]> {
    const data = await httpClient(`/api/v1/admin/groups/${groupId}/shares`, {
      method: 'GET',
      schema: ListAdminGroupSharesResponseSchema,
    })
    return data.shares ?? []
  },

  async setMember(
    groupId: number,
    userId: number,
    role: 'member' | 'manager'
  ): Promise<IamGroupMember> {
    const data = await httpClient(`/api/v1/admin/groups/${groupId}/members/${userId}`, {
      method: 'PUT',
      body: JSON.stringify({ role }),
      schema: PutAdminGroupMemberResponseSchema,
    })
    return data.member
  },

  async removeMember(groupId: number, userId: number): Promise<void> {
    await httpClient(`/api/v1/admin/groups/${groupId}/members/${userId}`, {
      method: 'DELETE',
      schema: DeleteAdminGroupMemberResponseSchema,
    })
  },

  async listMyGroups(): Promise<IamMyGroup[]> {
    const data = await httpClient('/api/v1/groups/mine', {
      method: 'GET',
      schema: ListMyGroupsResponseSchema,
    })
    return data.groups ?? []
  },

  async countMyGrantsToGroup(id: number): Promise<number> {
    const data = await httpClient(`/api/v1/groups/${id}/granted-shares`, {
      method: 'GET',
      schema: CountMyGrantsToGroupResponseSchema,
    })
    return data.count
  },

  async leaveGroup(id: number, withdrawShares = false): Promise<void> {
    await httpClient(`/api/v1/groups/${id}/membership`, {
      method: 'DELETE',
      params: withdrawShares ? { withdrawShares: '1' } : undefined,
      schema: LeaveMyGroupResponseSchema,
    })
  },

  async listShares(kind: string, resource: string): Promise<IamShare[]> {
    const data = await httpClient('/api/v1/shares', {
      method: 'GET',
      params: { kind, resource },
      schema: ListSharesResponseSchema,
    })
    return data.shares ?? []
  },

  async grantShare(payload: {
    kind: string
    resource: string
    subjectType: string
    subjectId: number
    permission: string
  }): Promise<IamShare> {
    const data = await httpClient('/api/v1/shares', {
      method: 'POST',
      body: JSON.stringify(payload),
      schema: GrantShareResponseSchema,
    })
    return data.share
  },

  async revokeShare(
    kind: string,
    resource: string,
    subjectType: string,
    subjectId: number
  ): Promise<void> {
    await httpClient('/api/v1/shares', {
      method: 'DELETE',
      params: { kind, resource, subjectType, subjectId: String(subjectId) },
      schema: RevokeShareResponseSchema,
    })
  },

  async searchSubjects(q: string): Promise<{
    subjects: IamSubject[]
    personScope: 'shared-group' | 'everyone'
  }> {
    const data = await httpClient('/api/v1/iam/subjects', {
      method: 'GET',
      params: { q },
      schema: SearchIamSubjectsResponseSchema,
    })
    return {
      subjects: data.subjects ?? [],
      personScope: data.personScope === 'everyone' ? 'everyone' : 'shared-group',
    }
  },

  async listSharedWithMe(kind: string): Promise<IamSharedItem[]> {
    const data = await httpClient('/api/v1/me/shared', {
      method: 'GET',
      params: { kind },
      schema: ListSharedWithMeResponseSchema,
    })
    return data.items ?? []
  },

  /** Items of `kind` that reached me since I last opened my incoming list. */
  async countUnseenShared(kind: string): Promise<number> {
    const data = await httpClient('/api/v1/me/shared/unseen', {
      method: 'GET',
      params: { kind },
      schema: CountUnseenSharedResponseSchema,
    })
    return data.count ?? 0
  },

  /** Clears the "new" indicator for `kind` by moving my seen-watermark to now. */
  async markSharedSeen(kind: string): Promise<void> {
    await httpClient('/api/v1/me/shared/seen', {
      method: 'POST',
      body: JSON.stringify({ kind }),
      schema: MarkSharedSeenResponseSchema,
    })
  },

  /** Marks one shared item opened. Other unseen items of that kind stay new. */
  async markSharedItemSeen(kind: string, resourceId: string): Promise<void> {
    await httpClient('/api/v1/me/shared/opened', {
      method: 'POST',
      body: JSON.stringify({ kind, resourceId }),
      schema: MarkSharedItemSeenResponseSchema,
    })
  },

  async listAudit(params?: {
    actor?: number
    action?: string
    kind?: string
    from?: number
    to?: number
    cursor?: number
    limit?: number
  }): Promise<{ entries: IamAuditEntry[]; nextCursor: number | null }> {
    const query: Record<string, string> = {}
    if (params?.actor !== undefined) query.actor = String(params.actor)
    if (params?.action) query.action = params.action
    if (params?.kind) query.kind = params.kind
    if (params?.from !== undefined) query.from = String(params.from)
    if (params?.to !== undefined) query.to = String(params.to)
    if (params?.cursor !== undefined) query.cursor = String(params.cursor)
    if (params?.limit !== undefined) query.limit = String(params.limit)
    return httpClient('/api/v1/admin/audit', {
      method: 'GET',
      params: query,
      schema: ListAdminAuditResponseSchema,
    })
  },

  async getGroupConfig(groupId: number): Promise<{
    settings: Record<string, IamGroupConfigSetting>
    conflicts: Record<string, string[]>
  }> {
    const data = await httpClient(`/api/v1/admin/groups/${groupId}/config`, {
      method: 'GET',
      schema: GroupConfigResponseSchema,
    })
    return readGroupConfig(data)
  },

  async putGroupConfig(
    groupId: number,
    body: Record<string, unknown>
  ): Promise<{
    settings: Record<string, IamGroupConfigSetting>
    conflicts: Record<string, string[]>
  }> {
    const data = await httpClient(`/api/v1/admin/groups/${groupId}/config`, {
      method: 'PUT',
      body: JSON.stringify(body),
      schema: GroupConfigResponseSchema,
    })
    return readGroupConfig(data)
  },

  async listLocks(): Promise<Record<string, boolean>> {
    const data = await httpClient('/api/v1/admin/config/locks', {
      method: 'GET',
      schema: GetAdminConfigLocksResponseSchema,
    })
    return (data.locks ?? {}) as Record<string, boolean>
  },

  async patchLocks(body: Record<string, boolean>): Promise<Record<string, boolean>> {
    const data = await httpClient('/api/v1/admin/config/locks', {
      method: 'PATCH',
      body: JSON.stringify(body),
      schema: PatchAdminConfigLocksResponseSchema,
    })
    return (data.locks ?? {}) as Record<string, boolean>
  },

  async continueChat(chatId: number): Promise<{ id: number; title: string }> {
    const data = await httpClient(`/api/v1/chats/${chatId}/continue`, {
      method: 'POST',
      schema: ContinueSharedChatResponseSchema,
    })
    const chat = data.chat ?? {}
    if (typeof chat.id !== 'number') {
      throw new Error('Continue response did not include the new chat id')
    }
    return { id: chat.id, title: chat.title ?? '' }
  },
}
