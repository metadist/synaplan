import { ref } from 'vue'
import { profileApi } from '@/services/api/profileApi'
import { useAuthStore } from '@/stores/auth'
import { browserTimezone, isValidIanaTimezone } from '@/utils/zonedDay'

type AccountTimezoneState = 'idle' | 'loading' | 'ready' | 'error'

export type AccountTimezoneSource = 'profile' | 'device-saved' | 'device-unsaved'

export type EnsuredAccountTimezone = {
  tz: string
  source: AccountTimezoneSource
}

const timezone = ref('')
const state = ref<AccountTimezoneState>('idle')
let inflight: Promise<void> | null = null
let ensureInflight: Promise<EnsuredAccountTimezone | null> | null = null
let request = 0
/** `undefined` until the cache has been aligned to a signed-in user. */
let cachedForUserId: number | null | undefined

/** Drop the cached profile time zone so the next load reads it again. */
export function resetAccountTimezone(): void {
  request += 1
  timezone.value = ''
  state.value = 'idle'
  inflight = null
  ensureInflight = null
  cachedForUserId = undefined
}

/** Remember a time zone that was just saved on the profile. */
export function setAccountTimezone(value: string): void {
  request += 1
  timezone.value = value.trim()
  state.value = 'ready'
  inflight = null
  ensureInflight = null
  cachedForUserId = signedInUserId()
}

function signedInUserId(): number | null {
  const id = useAuthStore().user?.id
  return typeof id === 'number' ? id : null
}

function isImpersonatingNow(): boolean {
  return useAuthStore().isImpersonating === true
}

/** A different signed-in user must not keep the previous account's zone. */
function alignCacheToUser(): void {
  const id = signedInUserId()
  if (cachedForUserId === id) return
  request += 1
  timezone.value = ''
  state.value = 'idle'
  inflight = null
  ensureInflight = null
  cachedForUserId = id
}

async function loadProfile(): Promise<void> {
  alignCacheToUser()
  if (state.value === 'ready') return
  if (inflight) return inflight
  const ticket = request
  const userId = cachedForUserId
  state.value = 'loading'
  inflight = profileApi
    .getProfile()
    .then((response) => {
      if (ticket !== request || cachedForUserId !== userId) return
      const name = response.profile?.timezone
      timezone.value = typeof name === 'string' ? name.trim() : ''
      state.value = 'ready'
    })
    .catch(() => {
      if (ticket !== request || cachedForUserId !== userId) return
      state.value = 'error'
    })
    .finally(() => {
      if (ticket === request) inflight = null
    })
  return inflight
}

async function readOrSave(userId: number | null): Promise<EnsuredAccountTimezone | null> {
  await loadProfile()
  if (signedInUserId() !== userId) return null
  if (state.value === 'error') return null

  const stored = timezone.value.trim()
  if (isValidIanaTimezone(stored)) {
    return { tz: stored, source: 'profile' }
  }

  const device = browserTimezone()
  if (!isValidIanaTimezone(device)) return null
  if (userId === null || isImpersonatingNow()) {
    return { tz: device, source: 'device-unsaved' }
  }

  try {
    await profileApi.updateProfile({ timezone: device })
  } catch {
    if (signedInUserId() !== userId) return null
    return { tz: device, source: 'device-unsaved' }
  }

  if (signedInUserId() !== userId) return null
  setAccountTimezone(device)
  return { tz: device, source: 'device-saved' }
}

/**
 * Profile time zone when one is stored. When the profile has none, save the
 * device zone once — except while impersonating, or when the save is rejected.
 * `null` means the profile could not be read, so nothing was written.
 * Concurrent callers share one request.
 */
export async function ensureAccountTimezone(): Promise<EnsuredAccountTimezone | null> {
  alignCacheToUser()
  if (ensureInflight) return ensureInflight
  const userId = signedInUserId()
  const ticket = request
  ensureInflight = readOrSave(userId).finally(() => {
    if (ticket === request) ensureInflight = null
  })
  return ensureInflight
}

/**
 * The signed-in account's profile time zone, loaded once and shared.
 * An empty string means the profile has none. `error` means the profile
 * could not be read.
 */
export function useAccountTimezone() {
  return { timezone, state, load: loadProfile, ensureAccountTimezone, isValidIanaTimezone }
}
