import type { APIRequestContext } from '@playwright/test'
import { expect } from '@playwright/test'
import { URLS, INTERVALS } from '../config/config'

/** MailHog message shape. API returns { items: [...] } or { messages: [...] }. */
interface MailHogMessage {
  Content?: {
    Body?: string
    Parts?: Array<{ Body?: string }>
    Headers?: Record<string, string | string[]>
  }
}

const VERIFY_MARKER = 'verify-email-callback?token='
const HREF_RE = /href=["']([^"']*\/verify-email-callback\?token=[^"']*)["']/i

/** GET MailHog messages. Non-OK tolerated (returns []); poll timeout = fail. */
export async function fetchMessages(request: APIRequestContext): Promise<MailHogMessage[]> {
  const res = await request.get(`${URLS.MAILHOG_URL}/api/v2/messages`)
  if (!res.ok()) return []
  const data = await res.json()
  const list = data.items ?? data.messages
  return Array.isArray(list) ? list : []
}

/** Body from Content.Body or first Part. */
export function bodyOf(msg: MailHogMessage): string {
  return msg.Content?.Body ?? msg.Content?.Parts?.[0]?.Body ?? ''
}

/**
 * Plain-text body for assertion. For multipart emails (e.g. text/plain + text/html),
 * MailHog may return the full MIME in Body; this extracts the text/plain part.
 */
export function getPlainTextBody(msg: MailHogMessage): string {
  const raw = bodyOf(msg)
  const decoded = decodeQP(raw)
  const plain = extractPlainFromMultipart(decoded)
  return decodeQP(plain)
}

/** Extract text/plain part from a decoded MIME body, or return as-is if no boundary. */
function extractPlainFromMultipart(decoded: string): string {
  const ctPlain =
    /Content-Type:\s*text\/plain[\s\S]*?\r?\n\r?\n([\s\S]*?)(?=\r?\n--|\r?\nContent-Type:|$)/i
  const m = decoded.match(ctPlain)
  if (m?.[1]) return m[1].trim()
  return decoded
}

/** Quoted-printable decode (Symfony Mailer sends HTML as QP). */
export function decodeQP(s: string): string {
  const noSoft = s.replace(/=\r?\n/g, '')
  return noSoft.replace(/=([0-9A-Fa-f]{2})/g, (_, hex: string) =>
    String.fromCharCode(parseInt(hex, 16))
  )
}

/** To header matches email (normalizes once). "Name <addr>" or plain addr, case-insensitive. */
export function toMatches(msg: MailHogMessage, recipientEmail: string): boolean {
  const want = recipientEmail.trim().toLowerCase()
  const toHeader = msg.Content?.Headers?.To ?? []
  const rawList = Array.isArray(toHeader) ? toHeader : [toHeader]
  for (const raw of rawList) {
    const s = String(raw).trim().toLowerCase()
    const angle = /<([^>]+)>/g
    let m: RegExpExecArray | null
    while ((m = angle.exec(s)) !== null) {
      if (m[1].trim() === want) return true
    }
    if (s === want) return true
  }
  return false
}

/** Clear MailHog inbox. Throws on API error (fail-fast). */
export async function clearMailHog(request: APIRequestContext): Promise<void> {
  const res = await request.delete(`${URLS.MAILHOG_URL}/api/v1/messages`)
  if (!res.ok()) {
    throw new Error(`MailHog clear failed: ${res.status()}`)
  }
}

/** Poll until verification email; return href. Fails by timeout if mail never arrives or API stays non-OK. */
export async function waitForVerificationHref(
  request: APIRequestContext,
  recipientEmail: string,
  opts?: { timeout?: number; intervals?: [number, number] }
): Promise<string> {
  const timeout = opts?.timeout ?? 60_000
  const intervals = opts?.intervals ?? INTERVALS.FAST()
  let href: string | null = null
  await expect
    .poll(
      async () => {
        const messages = await fetchMessages(request)
        for (const msg of messages) {
          if (!toMatches(msg, recipientEmail)) continue
          const decoded = decodeQP(bodyOf(msg))
          if (!decoded.toLowerCase().includes(VERIFY_MARKER)) continue
          const m = decoded.match(HREF_RE)
          if (m?.[1]) {
            href = m[1]
            return href
          }
        }
        return null
      },
      { timeout, intervals }
    )
    .not.toBeNull()
  return href!
}

/** Path or full URL → full URL with BASE_URL. */
export function normalizeVerificationUrl(hrefOrUrl: string): string {
  let path = hrefOrUrl
  if (path.startsWith('http://') || path.startsWith('https://')) {
    const url = new URL(path)
    path = url.pathname + url.search
  }
  if (!path.startsWith('/')) path = '/' + path
  return new URL(path, URLS.BASE_URL).toString()
}
