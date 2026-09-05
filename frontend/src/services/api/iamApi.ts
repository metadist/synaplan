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
} from '@/generated/api-schemas'

export type IamGroup = NonNullable<z.infer<typeof ListAdminGroupsResponseSchema>['groups']>[number]
export type IamGroupMember = NonNullable<
  z.infer<typeof ListAdminGroupMembersResponseSchema>['members']
>[number]

const IamShareSchema = z.object({
  id: z.number(),
  kind: z.string(),
  resourceId: z.string(),
  subjectType: z.string(),
  subjectId: z.number(),
  permission: z.string(),
  name: z.string().optional(),
  email: z.string().nullable().optional(),
  grantedBy: z.number().optional(),
  created: z.number().optional(),
})

const ListSharesResponseSchema = z.object({ shares: z.array(IamShareSchema).optional() })
const GrantShareResponseSchema = z.object({ share: IamShareSchema })
const RevokeShareResponseSchema = z.object({ success: z.boolean() })
const IamSubjectSchema = z.object({
  type: z.string(),
  id: z.number(),
  name: z.string(),
  email: z.string().nullable().optional(),
  pinned: z.boolean(),
})
const SearchSubjectsResponseSchema = z.object({ subjects: z.array(IamSubjectSchema).optional() })
const IamSharedItemSchema = z.object({
  id: z.string(),
  name: z.string(),
  icon: z.string(),
  meta: z.record(z.string(), z.unknown()).optional(),
  permission: z.string(),
  ownerId: z.number().nullable().optional(),
  ownerName: z.string().nullable().optional(),
})
const ListSharedWithMeResponseSchema = z.object({ items: z.array(IamSharedItemSchema).optional() })
const ContinueChatResponseSchema = z.object({
  success: z.boolean(),
  chat: z.object({
    id: z.number(),
    title: z.string(),
    createdAt: z.string().optional(),
    updatedAt: z.string().optional(),
    access: z.string().optional(),
  }),
})

export type IamShare = z.infer<typeof IamShareSchema>
export type IamSubject = z.infer<typeof IamSubjectSchema>
export type IamSharedItem = z.infer<typeof IamSharedItemSchema>

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
      schema: SearchSubjectsResponseSchema,
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

  async continueChat(chatId: number): Promise<{ id: number; title: string }> {
    const data = await httpClient(`/api/v1/chats/${chatId}/continue`, {
      method: 'POST',
      schema: ContinueChatResponseSchema,
    })
    return data.chat
  },
}
