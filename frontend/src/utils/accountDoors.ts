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
export type DoorSection = 'access' | 'oidc' | 'oidc_access'
export type DoorPin =
  | 'REGISTRATION_ENABLED'
  | 'SIGNUP_PAUSED'
  | 'REGISTRATION_MODE'
  | 'OIDC_ALLOW_USER_PROVISIONING'
  | 'GUEST_CHAT_ENABLED'
export type DoorField = DoorPin | 'OIDC_DISCOVERY_URL' | 'OIDC_CLIENT_ID'
export type SocialDoorProvider = 'google' | 'github' | 'apple'

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
  pin: DoorPin | null
  section: DoorSection | null
  field: DoorField | null
  /** Administrators create accounts on Users. There is no switch on this page. */
  people: boolean
  /** A human check is in front of self-sign-up. The keys themselves stay hidden. */
  humanCheck: boolean
  /** Social providers currently enabled for self-sign-up / sign-in. Fixed ids only. */
  socialProviders: readonly SocialDoorProvider[]
  /** Organization, role or permission claims restrict the company login. */
  restricted: boolean
}

type Reading = { on: boolean; source: DoorSource }

const SELF_SIGNUP = 'selfSignup'
const DIRECTORY = 'directory'
const COMMON_PLACEHOLDERS = ['change-me', 'your_site_key_here', 'your_secret_key_here']

function effectiveRaw(value: DoorValue): string {
  return value.envOverride ? (value.effectiveValue ?? value.value) : value.value
}

/**
 * Matches PHP's FILTER_VALIDATE_BOOL used by OidcAccessPolicy, RecaptchaService,
 * RegistrationConfig, SignupPauseSwitch and GuestChatConfig.
 */
function parseBool(raw: string): boolean {
  const normalized = raw.trim().toLowerCase()
  return normalized === 'true' || normalized === '1' || normalized === 'yes' || normalized === 'on'
}

function reading(value: DoorValue | undefined, fallback: boolean, envOnly: boolean): Reading {
  if (!value || !value.isSet) {
    return { on: fallback, source: 'default' }
  }
  const raw = effectiveRaw(value).trim()
  if (raw === '') {
    return { on: fallback, source: 'default' }
  }
  const on = parseBool(raw)
  if (value.envOverride || envOnly) {
    return { on, source: 'env' }
  }
  return { on, source: 'admin' }
}

function mode(value: DoorValue | undefined): { mode: 'open' | 'admission'; source: DoorSource } {
  if (!value || !value.isSet) {
    return { mode: 'open', source: 'default' }
  }
  const raw = effectiveRaw(value).trim().toLowerCase()
  return {
    mode: raw === 'admission' ? 'admission' : 'open',
    source: value.envOverride ? 'env' : 'admin',
  }
}

function realSetting(
  value: DoorValue | undefined,
  extraPlaceholders: readonly string[] = []
): boolean {
  if (value?.isSet !== true) return false
  if (value.isMasked) return true
  const trimmed = effectiveRaw(value).trim()
  if (trimmed === '') return false
  const lower = trimmed.toLowerCase()
  if (COMMON_PLACEHOLDERS.includes(lower)) return false
  return !extraPlaceholders.some((placeholder) => placeholder.toLowerCase() === lower)
}

function humanCheckOn(values: Record<string, DoorValue | undefined>): boolean {
  const turnstile =
    realSetting(values.TURNSTILE_SITE_KEY) && realSetting(values.TURNSTILE_SECRET_KEY)
  if (turnstile) return true
  const recaptcha = reading(values.RECAPTCHA_ENABLED, false, true).on
  if (!recaptcha || !realSetting(values.RECAPTCHA_SECRET_KEY)) return false
  if (values.RECAPTCHA_SITE_KEY?.isSet && !realSetting(values.RECAPTCHA_SITE_KEY)) {
    return false
  }
  return true
}

function socialProvidersOn(
  values: Record<string, DoorValue | undefined>
): readonly SocialDoorProvider[] {
  const active: SocialDoorProvider[] = []
  if (realSetting(values.GOOGLE_CLIENT_ID, ['your-google-client-id'])) active.push('google')
  if (realSetting(values.GITHUB_CLIENT_ID, ['your-github-client-id'])) active.push('github')
  if (realSetting(values.APPLE_CLIENT_ID, ['your-apple-client-id'])) active.push('apple')
  return active
}

function selfSignup(values: Record<string, DoorValue | undefined>): AccountDoor {
  const enabled = reading(values.REGISTRATION_ENABLED, true, false)
  const paused = reading(values.SIGNUP_PAUSED, false, false)
  const registrationMode = mode(values.REGISTRATION_MODE)
  const check = humanCheckOn(values)
  const social = socialProvidersOn(values)

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
      socialProviders: social,
      restricted: false,
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
      socialProviders: social,
      restricted: false,
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
      socialProviders: social,
      restricted: false,
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
    socialProviders: social,
    restricted: false,
  }
}

function directory(values: Record<string, DoorValue | undefined>): AccountDoor {
  if (!realSetting(values.OIDC_DISCOVERY_URL)) {
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
      socialProviders: [],
      restricted: false,
    }
  }

  const hasClient =
    realSetting(values.OIDC_CLIENT_ID, ['your-oidc-client-id']) ||
    realSetting(values.OIDC_BEARER_AUDIENCE)
  if (!hasClient) {
    return {
      id: DIRECTORY,
      state: 'unset',
      reason: 'missingClientId',
      source: 'default',
      pin: null,
      section: 'oidc',
      field: 'OIDC_CLIENT_ID',
      people: false,
      humanCheck: false,
      socialProviders: [],
      restricted: false,
    }
  }

  const provisioning = reading(values.OIDC_ALLOW_USER_PROVISIONING, true, true)
  const restricted =
    realSetting(values.OIDC_ORG_CODE) ||
    realSetting(values.OIDC_REQUIRED_ROLE) ||
    realSetting(values.OIDC_REQUIRED_PERMISSIONS)

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
    socialProviders: [],
    restricted,
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
    socialProviders: [],
    restricted: false,
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
    socialProviders: [],
    restricted: false,
  }
}

export function accountDoors(values: Record<string, DoorValue | undefined>): AccountDoor[] {
  return [selfSignup(values), directory(values), operator(), channel(values)]
}
