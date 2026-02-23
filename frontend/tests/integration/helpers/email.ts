import type { APIRequestContext } from '@playwright/test'
import { URLS } from '../../e2e/config/config'

/** MailHog message shape. API returns { items: [...] } or { messages: [...] }. */
interface MailHogMessage {
  Content?: {
    Body?: string
    Parts?: Array<{ Body?: string }>
    Headers?: Record<string, string | string[]>
  }
}

/** GET MailHog messages. Throws on non-OK (fail-fast). */
export async function fetchMessages(request: APIRequestContext): Promise<MailHogMessage[]> {
  const res = await request.get(`${URLS.MAILHOG_URL}/api/v2/messages`)
  if (!res.ok()) {
    throw new Error(`MailHog GET messages failed: ${res.status()}`)
  }
  const data = await res.json()
  const list = data.items ?? data.messages
  return Array.isArray(list) ? list : []
}

/** Body from Content.Body or first Part. */
function bodyOf(msg: MailHogMessage): string {
  return msg.Content?.Body ?? msg.Content?.Parts?.[0]?.Body ?? ''
}

/** Quoted-printable decode (Symfony Mailer sends HTML as QP). */
function decodeQP(s: string): string {
  const noSoft = s.replace(/=\r?\n/g, '')
  return noSoft.replace(/=([0-9A-Fa-f]{2})/g, (_, hex: string) =>
    String.fromCharCode(parseInt(hex, 16))
  )
}

/** Extract text/plain part from a decoded MIME body, or return as-is if no boundary. */
function extractPlainFromMultipart(decoded: string): string {
  const ctPlain = /Content-Type:\s*text\/plain[\s\S]*?\r?\n\r?\n([\s\S]*?)(?=\r?\n--|\r?\nContent-Type:|$)/i
  const m = decoded.match(ctPlain)
  if (m?.[1]) return m[1].trim()
  return decoded
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
