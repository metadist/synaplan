import { describe, expect, it } from 'vitest'
import { mcpSignInErrorKey } from '@/components/config/mcpSignInError'
import { ApiError } from '@/services/api/httpClient'

describe('mcpSignInErrorKey', () => {
  it('names the sentence for a code the server sent', () => {
    const err = new ApiError(
      400,
      'This server does not support automatic app registration',
      'no_registration'
    )

    expect(mcpSignInErrorKey(err)).toBe('mcpServers.signInError.no_registration')
  })

  it('maps the callback reason from the query', () => {
    expect(mcpSignInErrorKey('denied')).toBe('mcpServers.signInError.denied')
  })

  it('never shows the server text: anything unknown is the general sentence', () => {
    expect(mcpSignInErrorKey(new Error('Could not load metadata (HTTP 404)'))).toBe(
      'mcpServers.signInError.unknown'
    )
    expect(mcpSignInErrorKey(new ApiError(400, 'x', 'something_new'))).toBe(
      'mcpServers.signInError.unknown'
    )
    expect(mcpSignInErrorKey(undefined)).toBe('mcpServers.signInError.unknown')
  })
})
