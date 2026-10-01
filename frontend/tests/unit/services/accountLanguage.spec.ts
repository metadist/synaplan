import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import { i18n, setLocale } from '@/i18n'
import { syncAccountLanguage } from '@/services/accountLanguage'

const updateProfile = vi.fn()
const user = ref<{ id: number; email: string; level: string; language?: string | null } | null>(
  null
)

vi.mock('@/services/authService', () => ({
  authService: {
    isAuthenticated: () => user.value !== null,
    getUser: () => user,
  },
}))

vi.mock('@/services/api/profileApi', () => ({
  profileApi: {
    updateProfile: (...args: unknown[]) => updateProfile(...args),
  },
}))

describe('account language sync', () => {
  beforeEach(async () => {
    updateProfile.mockReset()
    updateProfile.mockResolvedValue({ success: true })
    user.value = { id: 1, email: 'a@b.c', level: 'NEW', language: null }
    window.history.replaceState({}, '', '/')
    i18n.global.locale.value = 'en'
  })

  it('stores the current UI language when the account has none', async () => {
    await syncAccountLanguage(null)

    expect(updateProfile).toHaveBeenCalledWith({ language: 'en' })
    expect(user.value?.language).toBe('en')
  })

  it('applies a stored language and does not write it back', async () => {
    await syncAccountLanguage('de')

    expect(i18n.global.locale.value).toBe('de')
    expect(updateProfile).not.toHaveBeenCalled()
  })

  it('keeps a ?lang= override for this page load', async () => {
    window.history.replaceState({}, '', '/?lang=fr')

    await syncAccountLanguage('de')
    await syncAccountLanguage(null)

    expect(i18n.global.locale.value).toBe('en')
    expect(updateProfile).not.toHaveBeenCalled()
  })

  it('saves the profile when a signed-in user changes the UI language', async () => {
    const saved = await setLocale('fr')

    expect(saved).toBe(true)
    expect(updateProfile).toHaveBeenCalledWith({ language: 'fr' })
    expect(user.value?.language).toBe('fr')
  })

  it('does not call the profile API while logged out', async () => {
    user.value = null

    await setLocale('es')

    expect(updateProfile).not.toHaveBeenCalled()
    expect(i18n.global.locale.value).toBe('es')
  })
})
