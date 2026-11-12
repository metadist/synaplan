import { describe, expect, it } from 'vitest'
import { accountDoors, type DoorValue } from '@/utils/accountDoors'

const set = (value: string, extra: Partial<DoorValue> = {}): DoorValue => ({
  value,
  isSet: true,
  ...extra,
})

const door = (id: string, values: Record<string, DoorValue | undefined> = {}) => {
  const found = accountDoors(values).find((row) => row.id === id)
  if (!found) throw new Error(`missing door ${id}`)
  return found
}

describe('accountDoors', () => {
  it('leaves self-sign-up open on the application default and hides every secret or value', () => {
    const secret = 'turnstile-secret-value'
    const googleId = 'google-client-id-value'
    const orgCode = 'org-secret-code'
    const rows = accountDoors({
      TURNSTILE_SITE_KEY: set('0xpublic'),
      TURNSTILE_SECRET_KEY: { value: secret, isSet: true, isMasked: true },
      GOOGLE_CLIENT_ID: set(googleId),
      OIDC_DISCOVERY_URL: set('https://idp.example.test'),
      OIDC_CLIENT_ID: set('synaplan-oidc'),
      OIDC_ORG_CODE: set(orgCode),
    })
    const serialized = JSON.stringify(rows)
    expect(serialized).not.toContain(secret)
    expect(serialized).not.toContain('0xpublic')
    expect(serialized).not.toContain(googleId)
    expect(serialized).not.toContain('synaplan-oidc')
    expect(serialized).not.toContain(orgCode)
    expect(
      door('selfSignup', { TURNSTILE_SITE_KEY: set('0xpublic'), TURNSTILE_SECRET_KEY: set(secret) })
    ).toMatchObject({
      state: 'open',
      reason: 'open',
      source: 'default',
      humanCheck: true,
      socialProviders: [],
      field: 'REGISTRATION_ENABLED',
    })
  })

  it('closes every self-sign-up door when registration is pinned off', () => {
    expect(
      door('selfSignup', {
        REGISTRATION_ENABLED: set('true', { envOverride: true, effectiveValue: 'false' }),
      })
    ).toMatchObject({
      state: 'closed',
      reason: 'registrationOff',
      source: 'env',
      pin: 'REGISTRATION_ENABLED',
    })
  })

  it('a pause closes sign-up even while registration stays on', () => {
    expect(
      door('selfSignup', {
        REGISTRATION_ENABLED: set('true'),
        SIGNUP_PAUSED: set('true'),
      })
    ).toMatchObject({
      state: 'closed',
      reason: 'paused',
      source: 'admin',
      field: 'SIGNUP_PAUSED',
      pin: null,
    })
  })

  it('admission stays open and names the mode pin', () => {
    expect(
      door('selfSignup', {
        REGISTRATION_ENABLED: set('true'),
        REGISTRATION_MODE: set('open', { envOverride: true, effectiveValue: 'admission' }),
      })
    ).toMatchObject({
      state: 'open',
      reason: 'admission',
      source: 'env',
      pin: 'REGISTRATION_MODE',
      field: 'REGISTRATION_MODE',
    })
  })

  it('registration off wins over a pause and over admission', () => {
    expect(
      door('selfSignup', {
        REGISTRATION_ENABLED: set('false'),
        SIGNUP_PAUSED: set('true'),
        REGISTRATION_MODE: set('admission'),
      }).reason
    ).toBe('registrationOff')
  })

  it('reports configured social providers and ignores sample placeholders', () => {
    expect(
      door('selfSignup', {
        GOOGLE_CLIENT_ID: set('google-real.apps.googleusercontent.com'),
        GITHUB_CLIENT_ID: set('your-github-client-id'),
        APPLE_CLIENT_ID: set('change-me'),
      }).socialProviders
    ).toEqual(['google'])

    expect(
      door('selfSignup', {
        GOOGLE_CLIENT_ID: set('g-id'),
        GITHUB_CLIENT_ID: set('gh-id'),
        APPLE_CLIENT_ID: set('com.synaplan.web'),
      }).socialProviders
    ).toEqual(['google', 'github', 'apple'])
  })

  it('treats a missing or incomplete company login as not set up', () => {
    expect(door('directory')).toMatchObject({
      state: 'unset',
      reason: 'notConfigured',
      field: 'OIDC_DISCOVERY_URL',
    })

    expect(
      door('directory', {
        OIDC_DISCOVERY_URL: set('https://idp.example.test'),
        OIDC_CLIENT_ID: set('your-oidc-client-id'),
      })
    ).toMatchObject({
      state: 'unset',
      reason: 'missingClientId',
      section: 'oidc',
      field: 'OIDC_CLIENT_ID',
    })
  })

  it('a company login creates an account only while that switch is on and accepts env boolean variants', () => {
    expect(
      door('directory', {
        OIDC_DISCOVERY_URL: set('https://idp.example.test'),
        OIDC_CLIENT_ID: set('synaplan'),
      })
    ).toMatchObject({ state: 'open', reason: 'open', source: 'default', restricted: false })

    expect(
      door('directory', {
        OIDC_DISCOVERY_URL: set('https://idp.example.test'),
        OIDC_BEARER_AUDIENCE: set('https://api.synaplan.test'),
        OIDC_ALLOW_USER_PROVISIONING: set('1'),
        OIDC_REQUIRED_ROLE: set('employees'),
      })
    ).toMatchObject({
      state: 'open',
      reason: 'open',
      source: 'env',
      pin: 'OIDC_ALLOW_USER_PROVISIONING',
      restricted: true,
    })

    expect(
      door('directory', {
        OIDC_DISCOVERY_URL: set('https://idp.example.test'),
        OIDC_CLIENT_ID: set('synaplan'),
        OIDC_ALLOW_USER_PROVISIONING: set('0'),
      })
    ).toMatchObject({
      state: 'closed',
      reason: 'provisioningOff',
      source: 'env',
      pin: 'OIDC_ALLOW_USER_PROVISIONING',
    })
  })

  it('keeps administrator creation open regardless of registration', () => {
    expect(door('operator', { REGISTRATION_ENABLED: set('false') })).toMatchObject({
      state: 'open',
      reason: 'admin',
      source: 'role',
      people: true,
      field: null,
    })
  })

  it('says channels do not follow the registration switch', () => {
    expect(
      door('channel', {
        GUEST_CHAT_ENABLED: set('false', { envOverride: true, effectiveValue: 'false' }),
      })
    ).toMatchObject({
      state: 'closed',
      reason: 'guestOff',
      source: 'env',
      pin: 'GUEST_CHAT_ENABLED',
    })
    expect(door('channel', { REGISTRATION_ENABLED: set('false') })).toMatchObject({
      state: 'open',
      reason: 'guestOn',
      source: 'default',
    })
  })

  it('counts a human check only when real keys are present', () => {
    expect(
      door('selfSignup', {
        TURNSTILE_SITE_KEY: set('0xpublic'),
        TURNSTILE_SECRET_KEY: set('turnstile-secret'),
        RECAPTCHA_ENABLED: set('false'),
      }).humanCheck
    ).toBe(true)
    expect(
      door('selfSignup', {
        RECAPTCHA_ENABLED: set('1'),
        RECAPTCHA_SECRET_KEY: set('recaptcha-secret'),
        TURNSTILE_SITE_KEY: set('0xpublic'),
      }).humanCheck
    ).toBe(true)
    expect(
      door('selfSignup', {
        RECAPTCHA_ENABLED: set('true'),
        RECAPTCHA_SITE_KEY: set('your_site_key_here'),
        RECAPTCHA_SECRET_KEY: set('recaptcha-secret'),
      }).humanCheck
    ).toBe(false)
    expect(door('selfSignup', { TURNSTILE_SITE_KEY: set('0xpublic') }).humanCheck).toBe(false)
    expect(door('selfSignup', { RECAPTCHA_ENABLED: set('true') }).humanCheck).toBe(false)
  })
})
