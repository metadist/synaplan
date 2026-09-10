import { describe, expect, it } from 'vitest'
import { ApiError } from '@/services/api/httpClient'
import { FEATURE_NOT_CONFIGURED, featureNotConfigured } from '@/services/api/featureNotConfigured'

const gateBody = { error: FEATURE_NOT_CONFIGURED, module: 'whatsapp', docs: 'modules/whatsapp' }

describe('featureNotConfigured', () => {
  it('extracts module and docs from the module gate 404', () => {
    const error = new ApiError(404, 'Not found', FEATURE_NOT_CONFIGURED, gateBody)

    expect(featureNotConfigured(error)).toEqual({
      error: FEATURE_NOT_CONFIGURED,
      module: 'whatsapp',
      docs: 'modules/whatsapp',
    })
  })

  it('ignores a plain 404 without the gate body', () => {
    expect(
      featureNotConfigured(new ApiError(404, 'Not found', 'not_found', { error: 'not_found' }))
    ).toBeNull()
    expect(featureNotConfigured(new ApiError(404, 'Not found'))).toBeNull()
  })

  it('ignores other statuses even with a gate-shaped body', () => {
    expect(
      featureNotConfigured(new ApiError(503, 'Unavailable', FEATURE_NOT_CONFIGURED, gateBody))
    ).toBeNull()
  })

  it('ignores non-ApiError failures', () => {
    expect(featureNotConfigured(new Error('network'))).toBeNull()
    expect(featureNotConfigured(undefined)).toBeNull()
    expect(featureNotConfigured({ status: 404, details: gateBody })).toBeNull()
  })

  it('rejects a gate body without a module id', () => {
    const error = new ApiError(404, 'Not found', FEATURE_NOT_CONFIGURED, {
      error: FEATURE_NOT_CONFIGURED,
      module: '',
      docs: '',
    })

    expect(featureNotConfigured(error)).toBeNull()
  })
})
