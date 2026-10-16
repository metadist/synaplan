import { describe, expect, it } from 'vitest'
import { isAdminOnlyResult } from '@/composables/search/adminOnly'

describe('isAdminOnlyResult', () => {
  it('treats system settings and Operate pages as admin-only', () => {
    expect(isAdminOnlyResult({ kind: 'setting' })).toBe(true)
    expect(isAdminOnlyResult({ kind: 'page', route: '/admin' })).toBe(true)
    expect(isAdminOnlyResult({ kind: 'page', route: '/admin/setup?tab=search' })).toBe(true)
  })

  it('leaves personal results unmarked', () => {
    expect(isAdminOnlyResult({ kind: 'file', route: '/files?file=1' })).toBe(false)
    expect(isAdminOnlyResult({ kind: 'page', route: '/settings' })).toBe(false)
    expect(isAdminOnlyResult({ kind: 'page', route: '/administration' })).toBe(false)
    expect(isAdminOnlyResult({ kind: 'chat' })).toBe(false)
  })
})
