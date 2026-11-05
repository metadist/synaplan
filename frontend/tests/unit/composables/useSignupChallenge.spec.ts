import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const config = {
  turnstile: { enabled: false, siteKey: '' },
  recaptcha: { enabled: false, siteKey: '' },
}
const recaptchaGetToken = vi.fn(async () => 'rc-token')
let recaptchaEnabled = false
const native = { app: false, base: 'https://web.synaplan.com' }

vi.mock('@/stores/config', () => ({ useConfigStore: () => config }))
vi.mock('@/composables/useRecaptcha', () => ({
  useRecaptcha: () => ({ getToken: recaptchaGetToken, isEnabled: recaptchaEnabled }),
}))
vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => native.app,
  getNativeApiBaseUrl: () => native.base,
}))

import { useSignupChallenge } from '@/composables/useSignupChallenge'

describe('useSignupChallenge', () => {
  beforeEach(() => {
    config.turnstile = { enabled: false, siteKey: '' }
    config.recaptcha = { enabled: false, siteKey: '' }
    recaptchaEnabled = false
    native.app = false
    recaptchaGetToken.mockClear()
    delete window.turnstile
    document.getElementById('synaplan-turnstile-slot')?.remove()
    document.getElementById('synaplan-human-check-frame')?.remove()
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

  it('frames the site page in the native shell and accepts only that origin', async () => {
    native.app = true
    config.turnstile = { enabled: true, siteKey: '0xSITE' }
    const pending = useSignupChallenge().getToken('login')
    const frame = document.getElementById('synaplan-human-check-frame')
    expect(frame).toBeInstanceOf(HTMLIFrameElement)
    const iframe = frame as HTMLIFrameElement
    expect(iframe.src).toContain('https://web.synaplan.com/api/v1/auth/human-check')
    expect(iframe.src).toContain('action=login')
    expect(iframe.style.opacity).toBe('0')
    expect(window.turnstile).toBeUndefined()

    window.dispatchEvent(
      new MessageEvent('message', {
        origin: 'https://evil.example',
        source: iframe.contentWindow,
        data: { source: 'synaplan-human-check', status: 'token', token: 'stolen' },
      })
    )
    window.dispatchEvent(
      new MessageEvent('message', {
        origin: 'https://web.synaplan.com',
        source: iframe.contentWindow,
        data: { source: 'synaplan-human-check', status: 'interactive' },
      })
    )
    expect(iframe.style.opacity).toBe('1')
    expect(iframe.style.pointerEvents).toBe('auto')

    window.dispatchEvent(
      new MessageEvent('message', {
        origin: 'https://web.synaplan.com',
        source: iframe.contentWindow,
        data: { source: 'synaplan-human-check', status: 'token', token: 'from-site' },
      })
    )
    await expect(pending).resolves.toBe('from-site')
    expect(document.getElementById('synaplan-human-check-frame')).toBeNull()
    expect(recaptchaGetToken).not.toHaveBeenCalled()
  })

  it('shares an in-flight hosted check for the same action instead of cancelling it', async () => {
    native.app = true
    config.turnstile = { enabled: true, siteKey: '0xSITE' }
    const challenge = useSignupChallenge()
    const first = challenge.getToken('login')
    const iframe = document.getElementById('synaplan-human-check-frame') as HTMLIFrameElement
    const second = challenge.getToken('login')
    expect(document.getElementById('synaplan-human-check-frame')).toBe(iframe)

    window.dispatchEvent(
      new MessageEvent('message', {
        origin: 'https://web.synaplan.com',
        source: iframe.contentWindow,
        data: { source: 'synaplan-human-check', status: 'token', token: 'shared-token' },
      })
    )

    await expect(Promise.all([first, second])).resolves.toEqual(['shared-token', 'shared-token'])
  })
})
