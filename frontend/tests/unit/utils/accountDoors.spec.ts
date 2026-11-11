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
  it('leaves self-sign-up open on the application default and hides every secret', () => {
    const secret = 'turnstile-secret-value'
    const rows = accountDoors({
      TURNSTILE_SITE_KEY: set('0xpublic'),
      TURNSTILE_SECRET_KEY: { value: secret, isSet: true, isMasked: true },
    })
    expect(JSON.stringify(rows)).not.toContain(secret)
    expect(JSON.stringify(rows)).not.toContain('0xpublic')
    expect(
      door('selfSignup', { TURNSTILE_SITE_KEY: set('0xpublic'), TURNSTILE_SECRET_KEY: set(secret) })
    ).toMatchObject({
      state: 'open',
      reason: 'open',
      source: 'default',
      humanCheck: true,
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

  it('treats a missing company login as not set up', () => {
    expect(door('directory')).toMatchObject({
      state: 'unset',
      reason: 'notConfigured',
      field: 'OIDC_DISCOVERY_URL',
    })
  })

  it('a company login creates an account only while that switch is on', () => {
    expect(
      door('directory', {
        OIDC_DISCOVERY_URL: set('https://idp.example.test'),
      })
    ).toMatchObject({ state: 'open', reason: 'open', source: 'default' })

    expect(
      door('directory', {
        OIDC_DISCOVERY_URL: set('https://idp.example.test'),
        OIDC_ALLOW_USER_PROVISIONING: set('false'),
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

  it('counts a human check only when the keys that make it run are present', () => {
    expect(
      door('selfSignup', {
        TURNSTILE_SITE_KEY: set('0xpublic'),
        TURNSTILE_SECRET_KEY: set('turnstile-secret'),
        RECAPTCHA_ENABLED: set('false'),
      }).humanCheck
    ).toBe(true)
    expect(
      door('selfSignup', {
        RECAPTCHA_ENABLED: set('true'),
        RECAPTCHA_SECRET_KEY: set('recaptcha-secret'),
        TURNSTILE_SITE_KEY: set('0xpublic'),
      }).humanCheck
    ).toBe(true)
    expect(door('selfSignup', { TURNSTILE_SITE_KEY: set('0xpublic') }).humanCheck).toBe(false)
    expect(door('selfSignup', { RECAPTCHA_ENABLED: set('true') }).humanCheck).toBe(false)
  })
})
