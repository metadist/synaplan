import { describe, expect, it } from 'vitest'
import { socialProviderLabel } from '@/utils/socialProviderLabel'

const translate = (key: string) => (key === 'auth.enterpriseSso' ? 'Enterprise SSO' : key)

describe('socialProviderLabel', () => {
  it('uses the translated enterprise label when the administrator did not set one', () => {
    expect(
      socialProviderLabel(
        { id: 'keycloak', name: 'Enterprise SSO', custom_label: false },
        translate
      )
    ).toBe('Enterprise SSO')
    expect(socialProviderLabel({ id: 'keycloak', name: 'Keycloak' }, translate)).toBe(
      'Enterprise SSO'
    )
  })

  it('keeps an administrator-configured label', () => {
    expect(
      socialProviderLabel({ id: 'keycloak', name: 'Kinde', custom_label: true }, translate)
    ).toBe('Kinde')
  })

  it('leaves other providers unchanged', () => {
    expect(socialProviderLabel({ id: 'google', name: 'Google' }, translate)).toBe('Google')
  })
})
