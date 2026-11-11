/**
 * Read-only answer to "who can create an account here".
 *
 * The card derives each door from values the Authentication page already
 * loaded. It never returns a setting's text, so a Turnstile secret or an
 * OAuth client secret cannot leak into the list.
 */

export type DoorId = 'selfSignup' | 'directory' | 'operator' | 'channel'
export type DoorState = 'open' | 'closed' | 'unset'
export type DoorSource = 'env' | 'admin' | 'default' | 'role'

export interface DoorValue {
  value: string
  isSet: boolean
  isMasked?: boolean
  envOverride?: boolean
  effectiveValue?: string
}

export interface AccountDoor {
  id: DoorId
  state: DoorState
  reason: string
  source: DoorSource
  /** Environment variable that pins the deciding switch. Never a secret. */
  pin: string | null
  section: string | null
  field: string | null
  /** Administrators create accounts on Users. There is no switch on this page. */
  people: boolean
  /** A human check is in front of self-sign-up. The keys themselves stay hidden. */
  humanCheck: boolean
}

type Reading = { on: boolean; source: DoorSource }

const SELF_SIGNUP = 'selfSignup'
const DIRECTORY = 'directory'

function reading(value: DoorValue | undefined, fallback: boolean, envOnly: boolean): Reading {
  if (!value || !value.isSet) {
    return { on: fallback, source: 'default' }
  }
  const raw = value.envOverride ? (value.effectiveValue ?? value.value) : value.value
  const on = raw === 'true'
  if (value.envOverride || envOnly) {
    return { on, source: 'env' }
  }
  return { on, source: 'admin' }
}

function mode(value: DoorValue | undefined): { mode: 'open' | 'admission'; source: DoorSource } {
  if (!value || !value.isSet) {
    return { mode: 'open', source: 'default' }
  }
  const raw = value.envOverride ? (value.effectiveValue ?? value.value) : value.value
  return {
    mode: raw === 'admission' ? 'admission' : 'open',
    source: value.envOverride ? 'env' : 'admin',
  }
}

function configured(value: DoorValue | undefined): boolean {
  return value?.isSet === true
}

function humanCheckOn(values: Record<string, DoorValue | undefined>): boolean {
  const turnstile = configured(values.TURNSTILE_SITE_KEY) && configured(values.TURNSTILE_SECRET_KEY)
  if (turnstile) return true
  const recaptcha = reading(values.RECAPTCHA_ENABLED, false, false).on
  return recaptcha && configured(values.RECAPTCHA_SECRET_KEY)
}

function selfSignup(values: Record<string, DoorValue | undefined>): AccountDoor {
  const enabled = reading(values.REGISTRATION_ENABLED, true, false)
  const paused = reading(values.SIGNUP_PAUSED, false, false)
  const registrationMode = mode(values.REGISTRATION_MODE)
  const check = humanCheckOn(values)

  if (!enabled.on) {
    return {
      id: SELF_SIGNUP,
      state: 'closed',
      reason: 'registrationOff',
      source: enabled.source,
      pin: enabled.source === 'env' ? 'REGISTRATION_ENABLED' : null,
      section: 'access',
      field: 'REGISTRATION_ENABLED',
      people: false,
      humanCheck: check,
    }
  }

  if (paused.on) {
    return {
      id: SELF_SIGNUP,
      state: 'closed',
      reason: 'paused',
      source: paused.source,
      pin: paused.source === 'env' ? 'SIGNUP_PAUSED' : null,
      section: 'access',
      field: 'SIGNUP_PAUSED',
      people: false,
      humanCheck: check,
    }
  }

  if (registrationMode.mode === 'admission') {
    return {
      id: SELF_SIGNUP,
      state: 'open',
      reason: 'admission',
      source: registrationMode.source,
      pin: registrationMode.source === 'env' ? 'REGISTRATION_MODE' : null,
      section: 'access',
      field: 'REGISTRATION_MODE',
      people: false,
      humanCheck: check,
    }
  }

  return {
    id: SELF_SIGNUP,
    state: 'open',
    reason: 'open',
    source: enabled.source,
    pin: enabled.source === 'env' ? 'REGISTRATION_ENABLED' : null,
    section: 'access',
    field: 'REGISTRATION_ENABLED',
    people: false,
    humanCheck: check,
  }
}

function directory(values: Record<string, DoorValue | undefined>): AccountDoor {
  if (!configured(values.OIDC_DISCOVERY_URL)) {
    return {
      id: DIRECTORY,
      state: 'unset',
      reason: 'notConfigured',
      source: 'default',
      pin: null,
      section: 'oidc',
      field: 'OIDC_DISCOVERY_URL',
      people: false,
      humanCheck: false,
    }
  }

  const provisioning = reading(values.OIDC_ALLOW_USER_PROVISIONING, true, true)
  return {
    id: DIRECTORY,
    state: provisioning.on ? 'open' : 'closed',
    reason: provisioning.on ? 'open' : 'provisioningOff',
    source: provisioning.source,
    pin: provisioning.source === 'env' ? 'OIDC_ALLOW_USER_PROVISIONING' : null,
    section: 'oidc_access',
    field: 'OIDC_ALLOW_USER_PROVISIONING',
    people: false,
    humanCheck: false,
  }
}

function operator(): AccountDoor {
  return {
    id: 'operator',
    state: 'open',
    reason: 'admin',
    source: 'role',
    pin: null,
    section: null,
    field: null,
    people: true,
    humanCheck: false,
  }
}

function channel(values: Record<string, DoorValue | undefined>): AccountDoor {
  const guest = reading(values.GUEST_CHAT_ENABLED, true, false)
  return {
    id: 'channel',
    state: guest.on ? 'open' : 'closed',
    reason: guest.on ? 'guestOn' : 'guestOff',
    source: guest.source,
    pin: guest.source === 'env' ? 'GUEST_CHAT_ENABLED' : null,
    section: 'access',
    field: 'GUEST_CHAT_ENABLED',
    people: false,
    humanCheck: false,
  }
}

export function accountDoors(values: Record<string, DoorValue | undefined>): AccountDoor[] {
  return [selfSignup(values), directory(values), operator(), channel(values)]
}
