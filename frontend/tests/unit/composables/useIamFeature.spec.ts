import { beforeEach, describe, expect, it, vi } from 'vitest'

const getConfigSync = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

import { isIamGroupsEnabled, isIamSharingEnabled } from '@/composables/useIamFeature'

describe('useIamFeature', () => {
  beforeEach(() => {
    getConfigSync.mockReset()
  })

  it('is off when runtime flags are missing', () => {
    getConfigSync.mockReturnValue({ features: {} })

    expect(isIamGroupsEnabled()).toBe(false)
    expect(isIamSharingEnabled()).toBe(false)
  })

  it('reads iamSharing from runtime config', () => {
    getConfigSync.mockReturnValue({ features: { iamSharing: true } })

    expect(isIamSharingEnabled()).toBe(true)
  })
})
