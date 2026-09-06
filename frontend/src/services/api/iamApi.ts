import { z } from 'zod'
import { httpClient } from './httpClient'
import {
  ListAdminGroupsResponseSchema,
  CreateAdminGroupResponseSchema,
  UpdateAdminGroupResponseSchema,
  DeleteAdminGroupResponseSchema,
  ListAdminGroupMembersResponseSchema,
  PutAdminGroupMemberResponseSchema,
  DeleteAdminGroupMemberResponseSchema,
  ListMyGroupsResponseSchema,
  ListSharesResponseSchema,
  GrantShareResponseSchema,
  RevokeShareResponseSchema,
  SearchIamSubjectsResponseSchema,
  ListSharedWithMeResponseSchema,
  CountUnseenSharedResponseSchema,
  MarkSharedSeenResponseSchema,
  ContinueSharedChatResponseSchema,
  ListAdminAuditResponseSchema,
} from '@/generated/api-schemas'

export type IamAuditEntry = NonNullable<
  z.infer<typeof ListAdminAuditResponseSchema>['entries']
>[number]

export type IamGroup = NonNullable<z.infer<typeof ListAdminGroupsResponseSchema>['groups']>[number]
export type IamGroupMember = NonNullable<
  z.infer<typeof ListAdminGroupMembersResponseSchema>['members']
>[number]

export type IamShare = NonNullable<z.infer<typeof ListSharesResponseSchema>['shares']>[number]
export type IamSubject = NonNullable<
  z.infer<typeof SearchIamSubjectsResponseSchema>['subjects']
>[number]
export type IamSharedItem = NonNullable<
  z.infer<typeof ListSharedWithMeResponseSchema>['items']
>[number]

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

  async listMyGroups(): Promise<IamGroup[]> {
    const data = await httpClient('/api/v1/groups/mine', {
      method: 'GET',
      schema: ListMyGroupsResponseSchema,
    })
    return data.groups ?? []
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

  async searchSubjects(q: string): Promise<IamSubject[]> {
    const data = await httpClient('/api/v1/iam/subjects', {
      method: 'GET',
      params: { q },
      schema: SearchIamSubjectsResponseSchema,
    })
    return data.subjects ?? []
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
