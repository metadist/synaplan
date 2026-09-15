import { describe, expect, it } from 'vitest'
import { GetAdminGroupConfigResponseSchema } from '@/generated/api-schemas'
import { GroupConfigResponseSchema } from '@/services/api/iamApi'

const setting = { value: null, source: null, locked: false }
const payload = {
  settings: { 'DEFAULTMODEL.CHAT': setting },
  conflicts: { 'DEFAULTMODEL.CHAT': ['openai:gpt-4o:chat', 'anthropic:claude-sonnet-5:chat'] },
}

describe('group policy config schema', () => {
  it('accepts a JSON object for conflicts', () => {
    expect(GetAdminGroupConfigResponseSchema.parse({ ...payload, conflicts: {} })).toMatchObject({
      conflicts: {},
    })
    expect(GroupConfigResponseSchema.parse({ ...payload, conflicts: {} }).conflicts).toEqual({})
  })

  it('rejects PHP empty-array conflicts on the generated schema', () => {
    const result = GetAdminGroupConfigResponseSchema.safeParse({ ...payload, conflicts: [] })
    expect(result.success).toBe(false)
  })

  it('coerces PHP empty-array conflicts so the Policies tab can load', () => {
    expect(GroupConfigResponseSchema.parse({ ...payload, conflicts: [] }).conflicts).toEqual({})
  })

  it('rejects a non-empty conflicts list instead of discarding it', () => {
    const result = GroupConfigResponseSchema.safeParse({ ...payload, conflicts: ['bad'] })
    expect(result.success).toBe(false)
  })

  it('rejects a non-empty settings list instead of discarding it', () => {
    const result = GroupConfigResponseSchema.safeParse({ ...payload, settings: [setting] })
    expect(result.success).toBe(false)
  })
})
