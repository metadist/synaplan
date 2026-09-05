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
}
