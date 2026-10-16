import { beforeEach, describe, expect, it, vi } from 'vitest'

let admin = false

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isAdmin() {
      return admin
    },
  }),
}))

import { isAdminPreview, type AdminPreviewFeature } from '@/composables/useAdminPreview'

const TEST_FEATURE = 'test-preview' as AdminPreviewFeature

describe('useAdminPreview', () => {
  beforeEach(() => {
    admin = false
  })

  it('is true only for an admin on a listed feature', () => {
    expect(isAdminPreview(TEST_FEATURE, ['test-preview'])).toBe(false)

    admin = true
    expect(isAdminPreview(TEST_FEATURE, ['test-preview'])).toBe(true)
  })

  it('hides a feature id that is not in the preview list', () => {
    admin = true
    expect(isAdminPreview(TEST_FEATURE, ['other-preview'])).toBe(false)
  })
})
