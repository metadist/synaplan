/**
 * A random RFC 4122 version 4 UUID.
 *
 * Browsers expose crypto.randomUUID() only in a secure context (HTTPS or
 * localhost). A self-hosted install opened as http://<LAN address> still has
 * crypto.getRandomValues(), so the UUID is built from random bytes there.
 */
export function createUuid(): string {
  if (typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  const bytes = crypto.getRandomValues(new Uint8Array(16))
  bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40
  bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
