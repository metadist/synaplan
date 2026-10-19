import { describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import { ApiError } from '@/services/api/httpClient'
import { freeApiStartErrorKey } from '@/utils/freeApiCopy'

const CODES = [
  'freeapi_unreachable',
  'freeapi_chat_ready',
  'freeapi_own_endpoint',
  'freeapi_already_active',
  'freeapi_throttled',
  'freeapi_full_today',
  'freeapi_paused',
  'freeapi_blocked',
  'freeapi_expired',
  'freeapi_upgrade_required',
  'freeapi_setup_incomplete',
]

describe('freeApiStartErrorKey', () => {
  it.each(CODES)('gives %s a sentence of its own in every locale', (code) => {
    const key = freeApiStartErrorKey(new ApiError(409, 'x', code))

    expect(key).not.toBe('freeApi.startError.unknown')
    for (const locale of ['de', 'en', 'es', 'fr', 'tr']) {
      expect(i18n.global.te(key, locale)).toBe(true)
    }
  })

  it('falls back to the generic sentence for anything else', () => {
    expect(freeApiStartErrorKey(new ApiError(502, 'x', 'freeapi_invalid_response'))).toBe(
      'freeApi.startError.unknown'
    )
    expect(freeApiStartErrorKey(new ApiError(500, 'x'))).toBe('freeApi.startError.unknown')
    expect(freeApiStartErrorKey(new Error('network'))).toBe('freeApi.startError.unknown')
  })
})
