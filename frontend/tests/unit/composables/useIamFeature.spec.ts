import { beforeEach, describe, expect, it, vi } from 'vitest'

const getConfigSync = vi.fn()

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => getConfigSync(),
}))

import {
  isIamGroupsEnabled,
  isIamImpersonationDisabled,
  isIamPoliciesEnabled,
  isIamSharingEnabled,
} from '@/composables/useIamFeature'

describe('useIamFeature', () => {
  beforeEach(() => {
    getConfigSync.mockReset()
  })

  it('is off when runtime flags are missing', () => {
    getConfigSync.mockReturnValue({ features: {} })

    expect(isIamGroupsEnabled()).toBe(false)
    expect(isIamSharingEnabled()).toBe(false)
    expect(isIamImpersonationDisabled()).toBe(false)
    expect(isIamPoliciesEnabled()).toBe(false)
  })

  it('reads iamPolicies from runtime config', () => {
    getConfigSync.mockReturnValue({ features: { iamPolicies: true } })

    expect(isIamPoliciesEnabled()).toBe(true)
  })

  it('reads iamImpersonationDisabled from runtime config', () => {
    getConfigSync.mockReturnValue({ features: { iamImpersonationDisabled: true } })

    expect(isIamImpersonationDisabled()).toBe(true)
  })

  it('reads iamSharing from runtime config', () => {
    getConfigSync.mockReturnValue({ features: { iamSharing: true } })

    expect(isIamSharingEnabled()).toBe(true)
  })
})
