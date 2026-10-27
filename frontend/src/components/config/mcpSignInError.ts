import { ApiError } from '@/services/api/httpClient'

const KNOWN = new Set([
  'disabled',
  'calls_off',
  'address_not_allowed',
  'https_required',
  'unreachable',
  'no_signin',
  'metadata_invalid',
  'no_registration',
  'unsupported_method',
  'registration_refused',
  'server_changed',
  'expired',
  'exchange_failed',
  'denied',
  'missing_code',
])

/**
 * i18n key of the one sentence shown when a server sign-in cannot start or
 * finish. The server's own error text is never shown.
 */
export function mcpSignInErrorKey(reason: unknown): string {
  const code = reason instanceof ApiError ? reason.code : reason
  return typeof code === 'string' && KNOWN.has(code)
    ? `mcpServers.signInError.${code}`
    : 'mcpServers.signInError.unknown'
}
