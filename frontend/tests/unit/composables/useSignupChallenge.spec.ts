import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const config = {
  turnstile: { enabled: false, siteKey: '' },
  recaptcha: { enabled: false, siteKey: '' },
}
const recaptchaGetToken = vi.fn(async () => 'rc-token')
let recaptchaEnabled = false

vi.mock('@/stores/config', () => ({ useConfigStore: () => config }))
vi.mock('@/composables/useRecaptcha', () => ({
  useRecaptcha: () => ({ getToken: recaptchaGetToken, isEnabled: recaptchaEnabled }),
}))

import { useSignupChallenge } from '@/composables/useSignupChallenge'

describe('useSignupChallenge', () => {
  beforeEach(() => {
    config.turnstile = { enabled: false, siteKey: '' }
    config.recaptcha = { enabled: false, siteKey: '' }
    recaptchaEnabled = false
    recaptchaGetToken.mockClear()
    delete window.turnstile
    document.getElementById('synaplan-turnstile-slot')?.remove()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('has no provider and yields an empty token when nothing is configured', async () => {
    const challenge = useSignupChallenge()

    expect(challenge.isEnabled).toBe(false)
    expect(challenge.provider).toBeNull()
    await expect(challenge.getToken('register')).resolves.toBe('rc-token')
    expect(recaptchaGetToken).toHaveBeenCalledWith('register')
  })

  it('falls back to reCAPTCHA when Turnstile has no site key', async () => {
    recaptchaEnabled = true
    const challenge = useSignupChallenge()

    expect(challenge.provider).toBe('recaptcha')
    expect(challenge.isEnabled).toBe(true)
    await expect(challenge.getToken('login')).resolves.toBe('rc-token')
  })

  it('prefers Turnstile and resolves the token the widget delivers', async () => {
    config.turnstile = { enabled: true, siteKey: '0xSITE' }
    recaptchaEnabled = true
    const remove = vi.fn()
    window.turnstile = {
      render: vi.fn((_container: HTMLElement, options) => {
        options.callback?.('ts-token')
        return 'widget-1'
      }),
      execute: vi.fn(),
      remove,
    }

    const challenge = useSignupChallenge()
    expect(challenge.provider).toBe('turnstile')

    await expect(challenge.getToken('signup')).resolves.toBe('ts-token')
    expect(window.turnstile.render).toHaveBeenCalledWith(
      expect.any(HTMLElement),
      expect.objectContaining({
        sitekey: '0xSITE',
        action: 'signup',
        appearance: 'interaction-only',
      })
    )
    expect(remove).toHaveBeenCalledWith('widget-1')
    expect(recaptchaGetToken).not.toHaveBeenCalled()
    expect(document.getElementById('synaplan-turnstile-slot')).not.toBeNull()
  })

  it('yields an empty token when the widget reports an error (the server then fails closed)', async () => {
    config.turnstile = { enabled: true, siteKey: '0xSITE' }
    window.turnstile = {
      render: vi.fn((_container: HTMLElement, options) => {
        options['error-callback']?.()
        return 'widget-2'
      }),
      execute: vi.fn(),
      remove: vi.fn(),
    }

    await expect(useSignupChallenge().getToken('register')).resolves.toBe('')
  })
})
