/**
 * Cookie consent utilities for GDPR/DSGVO compliance
 */

const CONSENT_KEY = 'cookie_consent'
const CONSENT_VERSION = '1' // Increment when privacy policy changes

export interface CookieConsent {
  version: string
  analytics: boolean
  timestamp: number
}

/**
 * Get stored consent from localStorage
 */
export function getStoredConsent(): CookieConsent | null {
  try {
    const stored = localStorage.getItem(CONSENT_KEY)
    if (!stored) return null

    const consent = JSON.parse(stored) as CookieConsent

    // Check if consent version matches current version
    if (consent.version !== CONSENT_VERSION) {
      return null // Need to re-consent
    }

    return consent
  } catch {
    return null
  }
}

/**
 * Check if user has given analytics consent
 */
export function hasAnalyticsConsent(): boolean {
  const consent = getStoredConsent()
  return consent?.analytics === true
}

/**
 * Store consent in localStorage
 */
export function storeConsent(analytics: boolean): CookieConsent {
  const consent: CookieConsent = {
    version: CONSENT_VERSION,
    analytics,
    timestamp: Date.now(),
  }

  localStorage.setItem(CONSENT_KEY, JSON.stringify(consent))
  return consent
}
