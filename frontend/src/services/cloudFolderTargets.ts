import type { ConnectionItem } from '@/services/api/connectionsApi'

export const CLOUD_FOLDER_KINDS = ['nextcloud', 'opencloud'] as const
export type CloudFolderKind = (typeof CLOUD_FOLDER_KINDS)[number]

export type CloudFolderTargetItem = {
  id: number
  name: string
  kind: CloudFolderKind
  folder: string
  source: 'webdav' | 'platform'
  host: string
}

export type CopyLink = {
  id: number
  client: string
  host: string
  can_copy?: boolean
}

function sanitizeChannel(raw: string): string {
  const slug = raw
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9-]+/g, '-')
    .replace(/^-+|-+$/g, '')
  return slug.length > 32 ? slug.slice(0, 32) : slug
}

function storedChannel(connection: ConnectionItem): string {
  if (typeof connection.channel === 'string' && connection.channel.trim() !== '') {
    return sanitizeChannel(connection.channel)
  }
  const fromConfig = connection.config?.channel
  return typeof fromConfig === 'string' ? sanitizeChannel(fromConfig) : ''
}

/** Planner unique() stores nextcloud-2 / opencloud-x for a second folder. */
export function kindFromChannel(channel: string): CloudFolderKind | null {
  const match = /^(nextcloud|opencloud)(?:-(?:\d+|x))?$/.exec(channel)
  return match ? (match[1] as CloudFolderKind) : null
}

function canReceiveFile(connection: ConnectionItem): boolean {
  return connection.has_secret === true && connection.status === 'connected'
}

/**
 * Same rule as backend CloudFolderTarget: only Nextcloud / OpenCloud folders.
 * Generic WebDAV and Dropbox stay out of the Files "Send to cloud" list.
 */
export function cloudFolderKindFor(connection: ConnectionItem): CloudFolderKind | null {
  if (connection.type !== 'webdav') {
    return null
  }

  const stored = storedChannel(connection)
  if (stored !== '') {
    return kindFromChannel(stored)
  }

  const baseUrl = typeof connection.config?.base_url === 'string' ? connection.config.base_url : ''
  const haystack = `${connection.name} ${baseUrl}`.toLowerCase()
  if (haystack.includes('opencloud')) {
    return 'opencloud'
  }
  if (haystack.includes('nextcloud') || haystack.includes('owncloud')) {
    return 'nextcloud'
  }
  return null
}

export function cloudFolderTargetsFrom(connections: ConnectionItem[]): CloudFolderTargetItem[] {
  const out: CloudFolderTargetItem[] = []
  for (const connection of connections) {
    const kind = cloudFolderKindFor(connection)
    if (kind === null || !canReceiveFile(connection)) {
      continue
    }
    const id = Number.parseInt(connection.id, 10)
    if (!Number.isFinite(id) || id <= 0) {
      continue
    }
    const rawFolder = connection.config?.folder
    const folder =
      typeof rawFolder === 'string' && rawFolder.trim() !== '' ? rawFolder.trim() : 'Synaplan'
    out.push({ id, name: connection.name, kind, folder, source: 'webdav', host: '' })
  }
  return out
}

const IMAGE = new Set(['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'])
const AUDIO = new Set(['mp3', 'wav', 'ogg', 'm4a', 'flac'])
const VIDEO = new Set(['mp4', 'webm', 'mov'])

/** Kind folder a copy lands in, from the file name. */
export function landingFolder(fileName: string): string {
  const ext = fileName.includes('.') ? (fileName.split('.').pop() ?? '').toLowerCase() : ''
  if (IMAGE.has(ext)) return 'Synaplan/Images'
  if (AUDIO.has(ext)) return 'Synaplan/Audio'
  if (VIDEO.has(ext)) return 'Synaplan/Video'
  if (ext === 'ics') return 'Synaplan/Calendar'
  return 'Synaplan/Documents'
}

export function platformCopyTargets(links: CopyLink[]): CloudFolderTargetItem[] {
  const out: CloudFolderTargetItem[] = []
  for (const link of links) {
    if (link.can_copy !== true || link.id <= 0 || link.host.trim() === '') continue
    const kind = kindFromChannel(link.client) ?? (link.client === 'owncloud' ? 'nextcloud' : null)
    if (kind === null) continue
    out.push({
      id: link.id,
      name: link.host,
      kind,
      folder: 'Synaplan/Documents',
      source: 'platform',
      host: link.host,
    })
  }
  return out
}
