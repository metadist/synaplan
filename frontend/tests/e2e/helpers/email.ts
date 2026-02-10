import type { APIRequestContext } from '@playwright/test'
import { URLS, INTERVALS } from '../config/config'

/** MailHog v2 message item shape (minimal for our use) */
export interface MailHogMessage {
  Content?: {
    Body?: string
    Parts?: Array<{ Body?: string }>
    Headers?: Record<string, string | string[]>
  }
}

/**
 * Decode quoted-printable email body (e.g. verification emails).
 */
export function decodeQuotedPrintable(input: string): string {
  const withoutSoftBreaks = input.replace(/=\r?\n/g, '')
  return withoutSoftBreaks.replace(/=([0-9A-Fa-f]{2})/g, (_, hex: string) =>
    String.fromCharCode(parseInt(hex, 16))
  )
}

/**
 * Extract verification link from decoded email body.
 * Handles href, raw URL, or token-only format.
 */
export function extractVerificationLink(decodedBody: string): string | null {
  const linkFromHref = decodedBody.match(
    /href=["']([^"']*\/verify-email-callback\?token=[^"']*)["']/i
  )?.[1]
  if (linkFromHref) return linkFromHref
  const linkFromUrl = decodedBody.match(
    /(https?:\/\/[^\s<>"]+\/verify-email-callback\?token=[^\s<>"]+)/i
  )?.[1]
  if (linkFromUrl) return linkFromUrl
  const token = decodedBody.match(/token=([a-zA-Z0-9_-]+)/i)?.[1]
  if (token) return `/verify-email-callback?token=${token}`
  return null
}

/** Clear MailHog inbox (e.g. before registration/verification tests). */
export async function clearMailHog(request: APIRequestContext): Promise<void> {
  const res = await request.delete(`${URLS.MAILHOG_URL}/api/v1/messages`)
  if (!res.ok()) {
    throw new Error(`Failed to clear MailHog: ${res.status()}`)
  }
}

/** Decode full email body (Body + Parts) from a MailHog message. */
export function getDecodedEmailBody(msg: MailHogMessage): string {
  const body = msg.Content?.Body || ''
  const parts = Array.isArray(msg.Content?.Parts)
    ? msg.Content.Parts.map((p) => p.Body || '').filter(Boolean)
    : []
  const fullBody = parts.join('\n') || body
  return decodeQuotedPrintable(fullBody)
}

/** Turn verification link (path or absolute URL) into full URL with BASE_URL. */
export function normalizeVerificationUrl(verificationLink: string): string {
  let path = verificationLink
  if (path.startsWith('http://') || path.startsWith('https://')) {
    const url = new URL(path)
    path = url.pathname + url.search
  }
  if (!path.startsWith('/')) path = '/' + path
  return new URL(path, URLS.BASE_URL).toString()
}

/** Poll MailHog until a verification email for recipient appears; return that message. */
export async function waitForVerificationEmail(
  request: APIRequestContext,
  recipientEmail: string,
  options?: { timeout?: number; intervals?: [number, number] }
): Promise<MailHogMessage> {
  const timeout = options?.timeout ?? 60_000
  const intervals = options?.intervals ?? INTERVALS.FAST()
  let found: MailHogMessage | null = null
  const { expect } = await import('@playwright/test')
  await expect
    .poll(
      async () => {
        const res = await request.get(`${URLS.MAILHOG_URL}/api/v2/messages`)
        if (!res.ok()) return null
        const data = await res.json()
        const items: MailHogMessage[] = Array.isArray(data.items) ? data.items : []
        found =
          items.find((msg) => {
            const toHeader = msg.Content?.Headers?.To ?? []
            const toList = Array.isArray(toHeader) ? toHeader : [toHeader]
            const toMatches = toList.some((to: string) => to.includes(recipientEmail))
            const body = msg.Content?.Body || ''
            const partBodies = Array.isArray(msg.Content?.Parts)
              ? msg.Content.Parts.map((p) => p.Body || '').join(' ')
              : ''
            const contentLower = `${body} ${partBodies}`.toLowerCase()
            return toMatches && contentLower.includes('verify-email-callback')
          }) ?? null
        return found
      },
      { timeout, intervals }
    )
    .not.toBeNull()
  return found!
}
