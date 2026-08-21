import { describe, it, expect } from 'vitest'
import { resolveForcedPasswordChange, CHANGE_PASSWORD_ROUTE } from '@/router/forcedPasswordChange'

const navigation = (
  overrides: Partial<Parameters<typeof resolveForcedPasswordChange>[0]> = {}
) => ({
  authenticated: true,
  mustChangePassword: false,
  isPublicRoute: false,
  routeName: 'chat' as string | symbol | null | undefined,
  ...overrides,
})

describe('resolveForcedPasswordChange', () => {
  it('sends an account with a deployment-generated password to the change page', () => {
    expect(resolveForcedPasswordChange(navigation({ mustChangePassword: true }))).toBe('force')
  })

  it('leaves the change page itself reachable', () => {
    expect(
      resolveForcedPasswordChange(
        navigation({ mustChangePassword: true, routeName: CHANGE_PASSWORD_ROUTE })
      )
    ).toBeNull()
  })

  it('keeps public pages open, since they need no session', () => {
    expect(
      resolveForcedPasswordChange(
        navigation({ mustChangePassword: true, isPublicRoute: true, routeName: 'shared-chat' })
      )
    ).toBeNull()
  })

  it('does not interfere once the user chose their own password', () => {
    expect(resolveForcedPasswordChange(navigation())).toBeNull()
  })

  it('ignores the flag for signed-out visitors', () => {
    expect(
      resolveForcedPasswordChange(navigation({ authenticated: false, mustChangePassword: true }))
    ).toBeNull()
  })

  it('releases anyone who lands on the change page without needing it', () => {
    expect(resolveForcedPasswordChange(navigation({ routeName: CHANGE_PASSWORD_ROUTE }))).toBe(
      'release'
    )
  })
})
