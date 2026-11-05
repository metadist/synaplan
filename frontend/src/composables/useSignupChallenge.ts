import { getCurrentScope, onScopeDispose } from 'vue'
import { i18n } from '@/i18n'
import { getNativeApiBaseUrl, isNativeApp } from '@/services/api/nativeRuntime'
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
 *
 * The native shell cannot host that widget. Its document is
 * `capacitor://localhost` (iOS) or `https://localhost` (Android); Turnstile
 * only runs on http(s), and only on a hostname authorized for the widget.
 * The shell frames the site's human-check page instead and takes the token
 * from the posted message. The check stays invisible until Cloudflare asks
 * for a tap, same as on the web.
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
    let settled = false
    let timer = 0
    const fail = (error: Error) => {
      if (settled) return
      settled = true
      window.clearTimeout(timer)
      script.remove()
      scriptPromise = null
      reject(error)
    }
    timer = window.setTimeout(() => fail(new Error('Turnstile script timed out')), TOKEN_TIMEOUT_MS)
    script.onload = () => {
      if (window.turnstile) {
        if (settled) return
        settled = true
        window.clearTimeout(timer)
        resolve(window.turnstile)
        return
      }
      fail(new Error('Turnstile script loaded without an API'))
    }
    script.onerror = () => fail(new Error('Turnstile script failed to load'))
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

const HOSTED_MESSAGE_SOURCE = 'synaplan-human-check'
const HOSTED_FRAME_ID = 'synaplan-human-check-frame'
const HOSTED_READY_MS = 8_000
const HOSTED_TOKEN_MS = 45_000

interface HumanCheckMessage {
  source: typeof HOSTED_MESSAGE_SOURCE
  status: 'ready' | 'interactive' | 'token' | 'error'
  token?: string
}

function isHumanCheckMessage(data: unknown): data is HumanCheckMessage {
  if (typeof data !== 'object' || data === null) return false
  const record = data as Record<string, unknown>
  return (
    record.source === HOSTED_MESSAGE_SOURCE &&
    (record.status === 'ready' ||
      record.status === 'interactive' ||
      record.status === 'token' ||
      record.status === 'error')
  )
}

function challengeLanguage(): string {
  const code = String(i18n.global.locale.value).slice(0, 2).toLowerCase()
  return /^[a-z]{2}$/.test(code) ? code : 'auto'
}

function hostedPageUrl(action: string): string {
  const base = getNativeApiBaseUrl().replace(/\/$/, '')
  const params = new URLSearchParams({
    action,
    parent: window.location.origin,
    theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
    lang: challengeLanguage(),
  })
  return `${base}/api/v1/auth/human-check?${params}`
}

/**
 * One hosted check at a time. A repeat call for the same action shares the
 * in-flight check so a double tap neither destroys the frame nor resolves
 * the first submit with an empty token. A different action cancels the old
 * check first.
 */
let activeHosted: {
  action: string
  promise: Promise<string>
  cancel: () => void
} | null = null

/**
 * Token from the site page. The frame stays invisible (and does not catch
 * taps) until Cloudflare asks for an interaction.
 */
function hostedTurnstileToken(action: string): Promise<string> {
  if (activeHosted) {
    if (activeHosted.action === action) return activeHosted.promise
    activeHosted.cancel()
  }

  const pageUrl = hostedPageUrl(action)
  const origin = new URL(pageUrl).origin
  const frame = document.createElement('iframe')
  frame.id = HOSTED_FRAME_ID
  frame.title = i18n.global.t('auth.signupBlocked.challengeTitle')
  frame.setAttribute('referrerpolicy', 'no-referrer')
  frame.style.position = 'fixed'
  frame.style.right = 'max(1rem, env(safe-area-inset-right))'
  frame.style.bottom =
    'calc(1rem + env(safe-area-inset-bottom) + var(--keyboard-inset-height, 0px))'
  frame.style.width = 'min(300px, calc(100vw - 2rem))'
  frame.style.height = '65px'
  frame.style.border = '0'
  frame.style.opacity = '0'
  frame.style.pointerEvents = 'none'
  frame.style.zIndex = '2147483000'
  frame.style.background = 'transparent'
  frame.setAttribute('aria-hidden', 'true')
  document.getElementById(HOSTED_FRAME_ID)?.remove()
  document.body.appendChild(frame)

  let cancel = () => {}
  const promise = new Promise<string>((resolve) => {
    let settled = false
    let sawReady = false
    const finish = (token: string) => {
      if (settled) return
      settled = true
      if (activeHosted?.cancel === cancel) activeHosted = null
      window.removeEventListener('message', onMessage)
      window.clearTimeout(readyTimer)
      window.clearTimeout(tokenTimer)
      frame.remove()
      resolve(token)
    }
    cancel = () => finish('')

    const onMessage = (event: MessageEvent) => {
      if (event.origin !== origin) return
      if (frame.contentWindow !== null && event.source !== frame.contentWindow) return
      if (!isHumanCheckMessage(event.data)) return
      if ('ready' === event.data.status) {
        sawReady = true
        window.clearTimeout(readyTimer)
        return
      }
      if ('interactive' === event.data.status) {
        frame.style.opacity = '1'
        frame.style.pointerEvents = 'auto'
        frame.setAttribute('aria-hidden', 'false')
        return
      }
      if (
        'token' === event.data.status &&
        'string' === typeof event.data.token &&
        '' !== event.data.token
      ) {
        finish(event.data.token)
        return
      }
      finish('')
    }
    // The page says ready as soon as its script runs. No word by then means
    // the page is missing or blocked, so we do not sit on the button for the
    // full challenge window.
    const readyTimer = window.setTimeout(() => {
      if (!sawReady) finish('')
    }, HOSTED_READY_MS)
    const tokenTimer = window.setTimeout(() => finish(''), HOSTED_TOKEN_MS)
    window.addEventListener('message', onMessage)
    // Navigate only after the listener is up, so a fast page cannot answer
    // before we are listening.
    frame.src = pageUrl
  })
  activeHosted = { action, promise, cancel }
  return promise
}

export const useSignupChallenge = () => {
  const config = useConfigStore()
  const recaptcha = useRecaptcha()
  const turnstileSiteKey = config.turnstile.siteKey
  const turnstileEnabled = config.turnstile.enabled && turnstileSiteKey !== ''

  if (getCurrentScope()) {
    onScopeDispose(() => {
      activeHosted?.cancel()
    })
  }

  /**
   * A token for `action` ('register', 'login', 'signup' before an OAuth
   * redirect), or '' when no provider is configured or the check could not
   * be completed.
   */
  const getToken = async (action: string): Promise<string> => {
    if (turnstileEnabled) {
      try {
        if (isNativeApp()) return await hostedTurnstileToken(action)
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
