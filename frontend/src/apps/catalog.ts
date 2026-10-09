import type { Component } from 'vue'
import { isModuleConfigured } from '@/composables/useModuleFeature'
import { isDesktopAgentEnabled } from '@/composables/useDesktopAgentFeature'
import { isPlatformLinksEnabled } from '@/composables/usePlatformLinksFeature'
import { isHiggsfieldAccountsEnabled, loadGatewayEnabled } from '@/composables/useAiAccounts'
import { useAuthStore } from '@/stores/auth'
import { m365Api } from '@/services/api/m365Api'
import { dropboxApi } from '@/services/api/dropboxApi'

export type AppCategory = 'messengers' | 'website' | 'email' | 'files' | 'ai' | 'developer'

export const APP_CATEGORIES: readonly AppCategory[] = [
  'messengers',
  'website',
  'email',
  'files',
  'ai',
  'developer',
]

type PanelLoader = () => Promise<{ default: Component }>

export interface AppDefinition {
  /** URL segment of `/apps/:appId`; stable, never translated. */
  id: string
  category: AppCategory
  /** Iconify name of the brand or topic glyph. */
  icon: string
  /** Apps with an editor of their own open that page instead of the detail template. */
  to?: string
  /** Flag off ⇒ the app is absent from the directory and its URL is unknown. */
  available: () => boolean | Promise<boolean>
  panel?: PanelLoader
  panelProps?: Record<string, unknown>
}

/** Admins see an unconfigured OAuth provider so they can finish its setup; users do not. */
async function providerAvailable(status: () => Promise<{ available: boolean }>): Promise<boolean> {
  if (useAuthStore().isAdmin) return true
  try {
    return (await status()).available
  } catch {
    return false
  }
}

const always = (): boolean => true

export const APPS: readonly AppDefinition[] = [
  {
    id: 'telegram',
    category: 'messengers',
    icon: 'simple-icons:telegram',
    available: () => isModuleConfigured('telegram'),
    panel: () => import('@/components/config/TelegramChannelCard.vue'),
  },
  {
    id: 'whatsapp',
    category: 'messengers',
    icon: 'simple-icons:whatsapp',
    available: () => isModuleConfigured('whatsapp'),
    panel: () => import('@/components/apps/panels/WhatsAppAppPanel.vue'),
  },
  {
    id: 'widgets',
    category: 'website',
    icon: 'heroicons:chat-bubble-bottom-center-text',
    to: '/channels/widgets',
    available: always,
  },
  {
    id: 'email',
    category: 'email',
    icon: 'heroicons:envelope',
    available: always,
    panel: () => import('@/components/apps/panels/EmailAddressAppPanel.vue'),
  },
  {
    id: 'mailbox',
    category: 'email',
    icon: 'heroicons:inbox-stack',
    available: always,
    panel: () => import('@/components/apps/panels/MailboxAppPanel.vue'),
  },
  {
    id: 'microsoft365',
    category: 'files',
    icon: 'simple-icons:microsoftoffice',
    available: () => providerAvailable(() => m365Api.status()),
    panel: () => import('@/components/config/ConnectionsConfiguration.vue'),
    panelProps: { provider: 'm365' },
  },
  {
    id: 'dropbox',
    category: 'files',
    icon: 'simple-icons:dropbox',
    available: () => providerAvailable(() => dropboxApi.status()),
    panel: () => import('@/components/config/ConnectionsConfiguration.vue'),
    panelProps: { provider: 'dropbox' },
  },
  {
    id: 'webdav',
    category: 'files',
    icon: 'heroicons:folder-open',
    available: always,
    panel: () => import('@/components/config/ConnectionsConfiguration.vue'),
    panelProps: { provider: 'dav' },
  },
  {
    id: 'nextcloud',
    category: 'files',
    icon: 'simple-icons:nextcloud',
    available: () => isPlatformLinksEnabled(),
    panel: () => import('@/components/config/LinkedPlatformsConfiguration.vue'),
    panelProps: { client: 'nextcloud' },
  },
  {
    id: 'owncloud',
    category: 'files',
    icon: 'simple-icons:owncloud',
    available: () => isPlatformLinksEnabled(),
    panel: () => import('@/components/config/LinkedPlatformsConfiguration.vue'),
    panelProps: { client: 'owncloud' },
  },
  {
    id: 'outlook',
    category: 'email',
    icon: 'simple-icons:microsoftoutlook',
    available: () => isPlatformLinksEnabled(),
    panel: () => import('@/components/config/LinkedPlatformsConfiguration.vue'),
    panelProps: { client: 'outlook' },
  },
  {
    id: 'claude-code',
    category: 'ai',
    icon: 'simple-icons:anthropic',
    // Admins reach the user view while the gateway is off so they can check it before turning it on.
    available: () => useAuthStore().isAdmin || loadGatewayEnabled(),
    panel: () => import('@/components/config/MessagesGatewayConfiguration.vue'),
  },
  {
    id: 'higgsfield',
    category: 'ai',
    icon: 'heroicons:film',
    available: () => isHiggsfieldAccountsEnabled(),
    panel: () => import('@/components/config/HiggsfieldConnection.vue'),
  },
  {
    id: 'mcp',
    category: 'developer',
    icon: 'heroicons:server-stack',
    available: always,
    panel: () => import('@/components/config/McpServersConfiguration.vue'),
  },
  {
    id: 'custom-tools',
    category: 'developer',
    icon: 'heroicons:wrench-screwdriver',
    available: always,
    panel: () => import('@/components/config/CustomToolsConfiguration.vue'),
  },
  {
    id: 'desktop',
    category: 'developer',
    icon: 'heroicons:computer-desktop',
    available: () => isDesktopAgentEnabled(),
    panel: () => import('@/components/config/DesktopConfiguration.vue'),
  },
  {
    id: 'api',
    category: 'developer',
    icon: 'heroicons:key',
    available: always,
    panel: () => import('@/components/config/APIKeysConfiguration.vue'),
  },
]

export function findApp(id: string): AppDefinition | undefined {
  return APPS.find((app) => app.id === id)
}

/** i18n segment under `apps.items.*` — vue-i18n paths stay camelCase. */
export function appMessageKey(id: string): string {
  return id.replace(/-([a-z0-9])/g, (_, char: string) => char.toUpperCase())
}

export async function isAppAvailable(app: AppDefinition): Promise<boolean> {
  try {
    return await app.available()
  } catch {
    return false
  }
}

export async function availableApps(): Promise<AppDefinition[]> {
  const flags = await Promise.all(APPS.map((app) => isAppAvailable(app)))
  return APPS.filter((_, index) => flags[index])
}
