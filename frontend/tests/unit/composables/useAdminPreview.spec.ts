import { beforeEach, describe, expect, it, vi } from 'vitest'

let admin = false

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isAdmin() {
      return admin
    },
  }),
}))

import { isAdminPreview } from '@/composables/useAdminPreview'

describe('useAdminPreview', () => {
  beforeEach(() => {
    admin = false
  })

  it('is true only for an admin on a listed feature', () => {
    expect(isAdminPreview('telegram')).toBe(false)

    admin = true
    expect(isAdminPreview('telegram')).toBe(true)
  })

  it('hides a feature id that is not in the preview list', () => {
    admin = true
    expect(isAdminPreview('not-a-feature' as 'telegram')).toBe(false)
  })
})
