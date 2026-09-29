import { createHash } from 'node:crypto'

/** Synaplan client_id: 1–64 of letters, digits, `.`, `_`, `-`, `:`. */
export function clientId(kind, ...parts) {
  const raw = [kind, ...parts].join(':').replace(/[^A-Za-z0-9._:-]/g, '_')
  if (raw.length <= 64 && raw.length > 0) {
    return raw
  }
  const hash = createHash('sha256').update([kind, ...parts].join(':')).digest('hex').slice(0, 20)
  return `${kind}:${hash}`.slice(0, 64)
}
