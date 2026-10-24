import { useConfigStore } from '@/stores/config'
import { useRecaptcha } from './useRecaptcha'

/**
 * The human check in front of sign-up, sign-in and every OAuth start.
 *
 * Mirrors the server's SignupChallenge: Cloudflare Turnstile when the
 * instance has a site key, otherwise reCAPTCHA v3 when enabled, otherwise
 * nothing (development, self-hosting without keys). `getToken()` returns
 * '' when no provider is active; the server treats that as "no check
 * configured" only when it has no provider itself, so a token that cannot
 * be produced simply fails closed there.
 *
 * Turnstile runs in `interaction-only` appearance: invisible for the vast
 * majority of people, and only when Cloudflare needs an interaction does the
 * widget appear in a small fixed box at the bottom right of the page.
 */

const TURNSTILE_SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'
const TURNSTILE_SLOT_ID = 'synaplan-turnstile-slot'
const TOKEN_TIMEOUT_MS = 45_000

interface TurnstileRenderOptions {
  sitekey: string
  action?: string
  execution?: 'render' | 'execute'
  appearance?: 'always' | 'execute' | 'interaction-only'
  callback?: (token: string) => void
  'error-callback'?: () => void
  'expired-callback'?: () => void
  'timeout-callback'?: () => void
}

interface TurnstileApi {
  render(container: HTMLElement, options: TurnstileRenderOptions): string
  execute(widgetId: string): void
  remove(widgetId: string): void
}

declare global {
  interface Window {
    turnstile?: TurnstileApi
  }
}

let scriptPromise: Promise<TurnstileApi> | null = null

function loadTurnstile(): Promise<TurnstileApi> {
  if (window.turnstile) return Promise.resolve(window.turnstile)
  if (scriptPromise) return scriptPromise

  scriptPromise = new Promise<TurnstileApi>((resolve, reject) => {
    const script = document.createElement('script')
    script.src = TURNSTILE_SCRIPT
    script.async = true
    script.defer = true
    script.onload = () => {
      if (window.turnstile) resolve(window.turnstile)
      else reject(new Error('Turnstile script loaded without an API'))
    }
    script.onerror = () => {
      scriptPromise = null
      reject(new Error('Turnstile script failed to load'))
    }
    document.head.appendChild(script)
  })

  return scriptPromise
}

function challengeSlot(): HTMLElement {
  const existing = document.getElementById(TURNSTILE_SLOT_ID)
  if (existing) return existing

  const slot = document.createElement('div')
  slot.id = TURNSTILE_SLOT_ID
  // Out of the layout flow, above modals, only visible while Cloudflare asks
  // for an interaction (the widget is empty otherwise).
  slot.style.position = 'fixed'
  slot.style.right = '1rem'
  slot.style.bottom = '1rem'
  slot.style.zIndex = '2147483000'
  document.body.appendChild(slot)
  return slot
}

async function turnstileToken(siteKey: string, action: string): Promise<string> {
  const api = await loadTurnstile()
  const slot = challengeSlot()

  return new Promise<string>((resolve) => {
    let widgetId: string | null = null
    let settled = false
    const cleanup = () => {
      if (widgetId === null) return
      try {
        api.remove(widgetId)
      } catch {
        // The widget may already be gone; nothing to clean up.
      }
      widgetId = null
    }
    const finish = (token: string) => {
      if (settled) return
      settled = true
      window.clearTimeout(timer)
      cleanup()
      resolve(token)
    }
    const timer = window.setTimeout(() => finish(''), TOKEN_TIMEOUT_MS)

    try {
      widgetId = api.render(slot, {
        sitekey: siteKey,
        action,
        execution: 'execute',
        appearance: 'interaction-only',
        callback: (token: string) => finish(token),
        'error-callback': () => finish(''),
        'expired-callback': () => finish(''),
        'timeout-callback': () => finish(''),
      })
      // A widget that answered synchronously inside render() is settled
      // already and only needs removing; otherwise run the challenge.
      if (settled) cleanup()
      else api.execute(widgetId)
    } catch {
      finish('')
    }
  })
}

export const useSignupChallenge = () => {
  const config = useConfigStore()
  const recaptcha = useRecaptcha()
  const turnstileSiteKey = config.turnstile.siteKey
  const turnstileEnabled = config.turnstile.enabled && turnstileSiteKey !== ''

  /**
   * A token for `action` ('register', 'login', 'signup' before an OAuth
   * redirect), or '' when no provider is configured or the check could not
   * be completed.
   */
  const getToken = async (action: string): Promise<string> => {
    if (turnstileEnabled) {
      try {
        return await turnstileToken(turnstileSiteKey, action)
      } catch {
        return ''
      }
    }
    return recaptcha.getToken(action)
  }

  return {
    isEnabled: turnstileEnabled || recaptcha.isEnabled,
    provider: turnstileEnabled ? 'turnstile' : recaptcha.isEnabled ? 'recaptcha' : null,
    getToken,
  }
}
