export const I18N_NAMESPACES = [
  'core',
  'auth',
  'chat',
  'files',
  'knowledge',
  'assistants',
  'widgets',
  'admin',
  'config',
  'settings',
  'tools',
] as const

export type I18nNamespace = (typeof I18N_NAMESPACES)[number]

export const WIDGET_I18N_NAMESPACES = ['core', 'chat', 'widgets'] as const
export type WidgetI18nNamespace = (typeof WIDGET_I18N_NAMESPACES)[number]

/**
 * Sidebar / mobile-nav chrome. Loaded on every non-public route so shell
 * labels (`iam.incoming.*`, `settings.logout`, guest/auth, recent chats)
 * never render as raw keys. `core` is already implied by the loader.
 */
export const CHROME_I18N_NAMESPACES = ['chat', 'auth', 'admin', 'settings'] as const

/**
 * Top-level message keys owned by each namespace file. Keys never move across
 * files without updating this map and the locale JSON together.
 *
 * `auth` also accepts a few de-only leftovers (`login`, `register`, …) that
 * predate the gated `auth.*` tree; they are not listed here because English
 * does not define them.
 */
export const NAMESPACE_KEYS: Record<I18nNamespace, readonly string[]> = {
  core: [
    'announcements',
    'branding',
    'common',
    'cookies',
    'error',
    'forceUpdate',
    'header',
    'iap',
    'loading',
    'models',
    'native',
    'nav',
    'network',
    'notFound',
    'pageTitles',
    'realtime',
    'search',
    'shared',
    'sidebar',
    'system',
    'unsavedChanges',
    'updates',
    'welcome',
    'welcomeUser',
  ],
  auth: [
    'accountDeletion',
    'adminSetup',
    'auth',
    'biometricLock',
    'forcedPasswordChange',
    'guest',
    'localAiDownload',
    'nativeServer',
    'onboarding',
    'setup',
    'setupBanner',
  ],
  chat: [
    'approvals',
    'chat',
    'chatError',
    'chatInput',
    'chatMessage',
    'chatShare',
    'chats',
    'commands',
    'companionLinks',
    'incognito',
    'message',
    'messageRefs',
    'modelMix',
    'moderation',
    'processing',
    'promoTips',
    'selfAware',
    'summary',
    'taskPlan',
  ],
  files: ['fileMention', 'fileSelection', 'files', 'rag', 'storage', 'vectorStorage'],
  knowledge: ['feedback', 'memories'],
  assistants: ['assistants', 'bundle'],
  widgets: ['liveSupport', 'widget', 'widgetSessions', 'widgets'],
  admin: [
    'admin',
    'adminModelStatus',
    'aiInfra',
    'iam',
    'modules',
    'people',
    'platformConnect',
    'providerHelp',
    'statistics',
  ],
  config: ['config'],
  settings: [
    'export',
    'externalLink',
    'limitReached',
    'marketingNews',
    'paywall',
    'profile',
    'settings',
    'subscription',
    'usageTaximeter',
  ],
  tools: [
    'aiAccounts',
    'aiProvider',
    'channels',
    'compute',
    'customTools',
    'help',
    'jobs',
    'linkedPlatforms',
    'mail',
    'mcpServers',
    'messagesGateway',
    'plugins',
    'tools',
    'workflows',
  ],
}

export function isI18nNamespace(value: string): value is I18nNamespace {
  return (I18N_NAMESPACES as readonly string[]).includes(value)
}
