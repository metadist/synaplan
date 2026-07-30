import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'
import { isPurchaseAllowed } from '@/services/api/nativeServer'

/** Why the paywall is being shown — drives the headline and subline. */
export type PaywallReason = 'guest_limit' | 'quota_exhausted' | 'reminder'

export const PAYWALL_LAST_SHOWN_KEY = 'synaplan.paywallLastShownAt'

/** Minimum distance between two unprompted reminders. */
export const PAYWALL_REMINDER_INTERVAL_MS = 24 * 60 * 60 * 1000

/**
 * Tiers that see the recurring reminder. Paying tiers are excluded on purpose:
 * they only get the paywall when their monthly quota is actually exhausted.
 */
const REMINDER_LEVELS = ['NEW']

/**
 * Tiers with nothing left to upgrade to. BUSINESS is the top plan, ADMIN is
 * unlimited — neither must ever be shown an upgrade sheet.
 */
const TOP_LEVELS = ['BUSINESS', 'ADMIN']

function readLastShownAt(): number {
  try {
    const raw = localStorage.getItem(PAYWALL_LAST_SHOWN_KEY)
    const parsed = null === raw ? Number.NaN : Number.parseInt(raw, 10)
    return Number.isFinite(parsed) ? parsed : 0
  } catch {
    return 0
  }
}

function writeLastShownAt(timestamp: number): void {
  try {
    localStorage.setItem(PAYWALL_LAST_SHOWN_KEY, String(timestamp))
  } catch {
    // localStorage unavailable — the reminder just won't be throttled across reloads.
  }
}

/**
 * Owns who sees the subscription paywall and how often.
 *
 * Hard triggers (`guest_limit`, `quota_exhausted`) fire the moment the user is
 * actually blocked. The `reminder` trigger is opportunistic and rate-limited to
 * one prompt per {@link PAYWALL_REMINDER_INTERVAL_MS}.
 *
 * When there is no purchase channel — billing disabled, or the native app
 * pointed at a custom server — nothing is eligible and the caller falls back to
 * the plain signup/limit modals, so self-hosted installs are unaffected.
 */
export function usePaywallPrompt() {
  const authStore = useAuthStore()
  const config = useConfigStore()

  const isPaywallOpen = ref(false)
  const paywallReason = ref<PaywallReason>('reminder')

  function canSell(): boolean {
    return config.billing.enabled && isPurchaseAllowed()
  }

  /** True when the current principal still has a tier to upgrade to. */
  function hasUpgradePath(): boolean {
    if (!authStore.isAuthenticated) return true
    return !TOP_LEVELS.includes(authStore.userLevel)
  }

  /**
   * A hard trigger passes for anyone who can still buy something — the caller
   * only raises it once the allowance is actually gone. The `reminder` trigger
   * is narrower: guests and free accounts see it, paying tiers never do.
   */
  function isEligible(reason: PaywallReason): boolean {
    if (!canSell() || !hasUpgradePath()) return false

    if ('reminder' === reason) {
      if (!authStore.isAuthenticated) return true
      return REMINDER_LEVELS.includes(authStore.userLevel)
    }

    return true
  }

  /** True when the reminder is both eligible and outside its cool-down. */
  function shouldRemind(now: number = Date.now()): boolean {
    if (!isEligible('reminder')) return false
    return now - readLastShownAt() >= PAYWALL_REMINDER_INTERVAL_MS
  }

  /**
   * Open the paywall for `reason`. Returns false (and shows nothing) when the
   * current principal is not eligible, so the caller can fall back.
   */
  function openPaywall(reason: PaywallReason): boolean {
    if (!isEligible(reason)) return false
    paywallReason.value = reason
    isPaywallOpen.value = true
    writeLastShownAt(Date.now())
    return true
  }

  function closePaywall(): void {
    isPaywallOpen.value = false
  }

  return {
    isPaywallOpen,
    paywallReason,
    isEligible,
    shouldRemind,
    openPaywall,
    closePaywall,
  }
}
