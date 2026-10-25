import { z } from 'zod'
import {
  AdminSignupCode,
  AdminSignupCodeUse,
  CreateAdminSignupCodeResponseSchema,
  ListAdminSignupCodeUsesResponseSchema,
  ListAdminSignupCodesResponseSchema,
  RevokeAdminSignupCodeResponseSchema,
  UpdateAdminSignupCodeResponseSchema,
  createAdminSignupCode_Body,
  updateAdminSignupCode_Body,
} from '@/generated/api-schemas'
import { httpClient } from './httpClient'

export type SignupCode = z.infer<typeof AdminSignupCode>
export type SignupCodeUse = z.infer<typeof AdminSignupCodeUse>
export type SignupCodeLevel = SignupCode['level']
export type SignupCodeCreate = z.infer<typeof createAdminSignupCode_Body>
export type SignupCodeChanges = z.infer<typeof updateAdminSignupCode_Body>
export type SignupCodeCreated = z.infer<typeof CreateAdminSignupCodeResponseSchema>

const BASE = '/api/v1/admin/signup-codes'

export const signupCodesApi = {
  async list(): Promise<SignupCode[]> {
    const res = await httpClient(BASE, { schema: ListAdminSignupCodesResponseSchema })
    return res.codes
  },

  create(body: SignupCodeCreate): Promise<SignupCodeCreated> {
    return httpClient(BASE, {
      method: 'POST',
      body: JSON.stringify(body),
      schema: CreateAdminSignupCodeResponseSchema,
    })
  },

  async update(id: number, changes: SignupCodeChanges): Promise<SignupCode> {
    const res = await httpClient(`${BASE}/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(changes),
      schema: UpdateAdminSignupCodeResponseSchema,
    })
    return res.signupCode
  },

  async revoke(id: number): Promise<SignupCode> {
    const res = await httpClient(`${BASE}/${id}`, {
      method: 'DELETE',
      schema: RevokeAdminSignupCodeResponseSchema,
    })
    return res.signupCode
  },

  async uses(id: number): Promise<SignupCodeUse[]> {
    const res = await httpClient(`${BASE}/${id}/uses`, {
      schema: ListAdminSignupCodeUsesResponseSchema,
    })
    return res.uses
  },
}
