export type PlatformClientId = 'outlook' | 'nextcloud' | 'owncloud' | 'opencloud'

export interface PlatformClientPolicy {
  id: PlatformClientId
  labelKey: string
  delivery: 'fragment-payload' | 'link-code'
  flagGated: boolean
}

export const PLATFORM_CLIENTS: Record<PlatformClientId, PlatformClientPolicy> = {
  outlook: {
    id: 'outlook',
    labelKey: 'platformConnect.clients.outlook',
    delivery: 'fragment-payload',
    flagGated: false,
  },
  nextcloud: {
    id: 'nextcloud',
    labelKey: 'platformConnect.clients.nextcloud',
    delivery: 'link-code',
    flagGated: true,
  },
  owncloud: {
    id: 'owncloud',
    labelKey: 'platformConnect.clients.owncloud',
    delivery: 'link-code',
    flagGated: true,
  },
  opencloud: {
    id: 'opencloud',
    labelKey: 'platformConnect.clients.opencloud',
    delivery: 'link-code',
    flagGated: true,
  },
}

export function resolvePlatformClient(raw: unknown): PlatformClientPolicy | null {
  if (typeof raw !== 'string') return null
  const id = raw.trim().toLowerCase()
  if (id in PLATFORM_CLIENTS) {
    return PLATFORM_CLIENTS[id as PlatformClientId]
  }
  return null
}
