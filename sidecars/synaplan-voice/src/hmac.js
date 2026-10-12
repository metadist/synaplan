import { createHmac, timingSafeEqual } from 'node:crypto'

export function sign(secret, body, now = Date.now()) {
  const timestamp = String(Math.floor(now / 1000))
  const signature = createHmac('sha256', secret).update(`${timestamp}\n${body}`).digest('hex')
  return { timestamp, signature }
}

export function verify(secret, timestamp, signature, body, now = Date.now()) {
  if (!secret || !/^\d+$/.test(timestamp) || !signature) return false
  if (Math.abs(Math.floor(now / 1000) - Number(timestamp)) > 60) return false
  const expected = createHmac('sha256', secret).update(`${timestamp}\n${body}`).digest('hex')
  const left = Buffer.from(expected)
  const right = Buffer.from(String(signature).toLowerCase())
  if (left.length !== right.length) return false
  return timingSafeEqual(left, right)
}
