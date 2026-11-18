import { desktopPairingAddress } from '@/utils/desktopPairingAddress'
import { getNativeApiBaseUrl, isNativeApp } from '@/services/api/nativeRuntime'

export type PublicApiEndpointId = 'openai' | 'chat' | 'mcp'

export interface PublicApiEndpoint {
  id: PublicApiEndpointId
  url: string
}

/**
 * Origin that serves `/v1` and `/mcp`.
 *
 * Production serves the page and the API on one host. Local Vite is on
 * :5173 while the API is on :8000. The native shell's page origin is not
 * the API host.
 */
export function publicApiOrigin(pageOrigin: string = window.location.origin): string {
  const raw = isNativeApp() ? getNativeApiBaseUrl() : desktopPairingAddress(pageOrigin)
  return raw.replace(/\/$/, '')
}

/** Addresses a client pastes. The path is the whole explanation. */
export function publicApiEndpoints(pageOrigin?: string): PublicApiEndpoint[] {
  const origin = publicApiOrigin(pageOrigin)
  return [
    { id: 'openai', url: `${origin}/v1` },
    { id: 'chat', url: `${origin}/v1/chat/completions` },
    { id: 'mcp', url: `${origin}/mcp` },
  ]
}
