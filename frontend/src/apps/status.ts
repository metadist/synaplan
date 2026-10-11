import { getTelegramChannel } from '@/services/api/telegramChannelApi'
import { getWhatsAppAssistant } from '@/services/api/whatsappAssistantApi'
import { listWidgets } from '@/services/api/widgetsApi'
import { profileApi } from '@/services/api/profileApi'
import { inboundEmailHandlersApi } from '@/services/api/inboundEmailHandlersApi'
import { connectionsApi, type ConnectionItem } from '@/services/api/connectionsApi'
import { platformLinksApi, type PlatformLink } from '@/services/api/platformLinksApi'
import { getMessagesGatewayStatus } from '@/services/api/messagesGatewayApi'
import { getHiggsfieldCredentialState } from '@/services/api/higgsfieldCredentialsApi'
import { mcpServersApi } from '@/services/api/mcpServersApi'
import { customToolsApi } from '@/services/api/customToolsApi'
import { desktopApi } from '@/services/api/desktopApi'
import { listApiKeys } from '@/services/api/apiKeysApi'
import type { AppDefinition } from './catalog'

const DAV_TYPES = new Set(['webdav', 'caldav'])

/**
 * One status check per app, sharing the list requests several apps read.
 * A failed request means "unknown", never "connected".
 */
export async function loadConnectedAppIds(apps: readonly AppDefinition[]): Promise<Set<string>> {
  let connections: Promise<ConnectionItem[]> | null = null
  let platformLinks: Promise<PlatformLink[]> | null = null
  const listConnections = () => (connections ??= connectionsApi.list())
  const listPlatformLinks = () => (platformLinks ??= platformLinksApi.listMine())
  const hasConnection = async (match: (row: ConnectionItem) => boolean) =>
    (await listConnections()).some((row) => match(row) && row.status !== 'disconnected')
  const hasPlatformLink = async (client: string) =>
    (await listPlatformLinks()).some((link) => link.client === client)

  const checks: Record<string, () => Promise<boolean>> = {
    telegram: async () => (await getTelegramChannel()).status === 'connected',
    whatsapp: async () => (await getWhatsAppAssistant()) !== null,
    widgets: async () => (await listWidgets()).length > 0,
    email: async () => !!(await profileApi.getEmailKeyword()).keyword,
    mailbox: async () => (await inboundEmailHandlersApi.list()).length > 0,
    microsoft365: () => hasConnection((row) => row.type === 'm365'),
    dropbox: () => hasConnection((row) => row.type === 'dropbox'),
    webdav: () => hasConnection((row) => DAV_TYPES.has(row.type)),
    nextcloud: () => hasPlatformLink('nextcloud'),
    owncloud: () => hasPlatformLink('owncloud'),
    outlook: () => hasPlatformLink('outlook'),
    'claude-code': async () =>
      (await getMessagesGatewayStatus())?.keys?.anthropic?.has_user_key === true,
    higgsfield: async () => (await getHiggsfieldCredentialState()).effective_source === 'user',
    mcp: async () => (await mcpServersApi.list()).servers.length > 0,
    'custom-tools': async () => (await customToolsApi.list()).length > 0,
    desktop: async () => (await desktopApi.listDevices()).length > 0,
    api: async () => ((await listApiKeys()).api_keys ?? []).length > 0,
  }

  const results = await Promise.all(
    apps.map(async (app) => {
      const check = checks[app.id]
      if (!check) return null
      try {
        return (await check()) ? app.id : null
      } catch {
        return null
      }
    })
  )
  return new Set(results.filter((id): id is string => id !== null))
}
