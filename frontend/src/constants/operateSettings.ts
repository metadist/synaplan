/**
 * Where every system-config section lives in Operate.
 *
 * The backend schema (`GET /api/v1/admin/config/schema`) is the registry of
 * fields and sections; which page and tab shows a section is a UI decision
 * made here. Operate is grouped by topic: everything the AI needs (providers,
 * keys, model health, document reading, knowledge search, chat behaviour,
 * system prompts) lives on AI infrastructure, and System configuration keeps
 * the platform settings. A backend section this map does not know still
 * renders under "More settings" on System configuration, so it is never
 * unreachable.
 */

export interface ConfigSectionRef {
  /** Backend schema tab id (`ai`, `processing`, …). */
  tab: string
  /** Backend schema section id inside that tab. */
  section: string
}

const sectionRef = (tab: string, section: string): ConfigSectionRef => ({ tab, section })

export const AI_INFRA_PATH = '/admin/setup'
export const SYSTEM_CONFIG_PATH = '/admin/config'

export type AiTabId =
  'providers' | 'health' | 'documents' | 'search' | 'behavior' | 'prompts' | 'gateway'

export const AI_TAB_IDS: readonly AiTabId[] = [
  'providers',
  'health',
  'documents',
  'search',
  'behavior',
  'prompts',
  'gateway',
]

export function isAiTabId(value: unknown): value is AiTabId {
  return typeof value === 'string' && (AI_TAB_IDS as readonly string[]).includes(value)
}

/**
 * Backend sections rendered as settings on each AI infrastructure tab.
 * `ai.cloud` and `ai.media` mostly hold provider keys; those fields are
 * edited on the provider cards of the same tab, so only their remaining
 * fields (if any) are shown.
 */
export const AI_TAB_SECTIONS: Record<AiTabId, readonly ConfigSectionRef[]> = {
  providers: [
    sectionRef('ai', 'ollama'),
    sectionRef('ai', 'selfhosted'),
    sectionRef('ai', 'tts'),
    sectionRef('ai', 'cloud'),
    sectionRef('ai', 'media'),
  ],
  health: [],
  documents: [
    sectionRef('processing', 'tika'),
    sectionRef('processing', 'docling'),
    sectionRef('processing', 'rasterize'),
    sectionRef('processing', 'whisper'),
  ],
  search: [
    sectionRef('ai', 'embeddings'),
    sectionRef('vectordb', 'qdrant'),
    sectionRef('vectordb', 'qdrant_search'),
  ],
  behavior: [
    sectionRef('routing', 'multitask'),
    sectionRef('routing', 'conversation_summary'),
    sectionRef('routing', 'deep_memory'),
    sectionRef('processing', 'media'),
  ],
  prompts: [],
  gateway: [],
}

export type SystemGroupId = 'access' | 'features' | 'channels' | 'appearance' | 'more'

export interface SystemTabDef {
  /** Stable id used in `?tab=` and test ids. */
  id: string
  icon: string
  /** Every section of this backend tab, in backend order. */
  backendTab?: string
  /** Explicit sections, in display order (after `backendTab` sections). */
  sections?: readonly ConfigSectionRef[]
  /** A purpose-built panel shown above the settings. */
  panel?: 'web-search'
}

export interface SystemGroupDef {
  id: SystemGroupId
  tabs: readonly SystemTabDef[]
}

export const SYSTEM_CONFIG_GROUPS: readonly SystemGroupDef[] = [
  {
    id: 'access',
    tabs: [
      { id: 'auth', icon: 'mdi:shield-key', backendTab: 'auth' },
      { id: 'sharing', icon: 'mdi:account-group', backendTab: 'sharing' },
    ],
  },
  {
    id: 'features',
    tabs: [
      { id: 'features', icon: 'mdi:toggle-switch-outline', backendTab: 'features' },
      {
        id: 'web_search',
        icon: 'mdi:web',
        panel: 'web-search',
        sections: [sectionRef('processing', 'brave')],
      },
      {
        id: 'tools',
        icon: 'mdi:tools',
        sections: [
          sectionRef('routing', 'saved_tasks'),
          sectionRef('routing', 'tools'),
          sectionRef('processing', 'compute'),
        ],
      },
    ],
  },
  {
    id: 'channels',
    tabs: [
      { id: 'email', icon: 'mdi:email-outline', backendTab: 'email' },
      { id: 'channels', icon: 'mdi:message-text', backendTab: 'channels' },
      { id: 'mobile', icon: 'mdi:cellphone', backendTab: 'mobile' },
    ],
  },
  {
    id: 'appearance',
    tabs: [
      { id: 'branding', icon: 'mdi:palette', backendTab: 'branding' },
      { id: 'interface', icon: 'mdi:monitor-dashboard', backendTab: 'interface' },
      { id: 'guest_landing', icon: 'mdi:newspaper-variant-outline', backendTab: 'guest_landing' },
    ],
  },
]

/** Sections with a live connection check (`POST /admin/config/test/{service}`). */
export const SECTION_TEST_SERVICE: Readonly<Record<string, string>> = {
  'ai.ollama': 'ollama',
  'ai.tts': 'piper',
  'processing.tika': 'tika',
  'processing.docling': 'docling',
  'vectordb.qdrant': 'qdrant',
  'email.mailer': 'mailer',
}

export const sectionKey = (ref: ConfigSectionRef): string => `${ref.tab}.${ref.section}`

const aiSectionHome = new Map<string, AiTabId>()
for (const tabId of AI_TAB_IDS) {
  for (const ref of AI_TAB_SECTIONS[tabId]) {
    aiSectionHome.set(sectionKey(ref), tabId)
  }
}

const systemSectionHome = new Map<string, string>()
for (const group of SYSTEM_CONFIG_GROUPS) {
  for (const tab of group.tabs) {
    for (const ref of tab.sections ?? []) systemSectionHome.set(sectionKey(ref), tab.id)
  }
}

/**
 * Backend tabs that no longer exist as a tab of their own. A bookmark that
 * names only the tab lands where most of its settings went.
 */
const RETIRED_CONFIG_TAB_HOME: Readonly<Record<string, OperateSettingsTarget>> = {
  ai: { path: AI_INFRA_PATH, tab: 'providers' },
  processing: { path: AI_INFRA_PATH, tab: 'documents' },
  vectordb: { path: AI_INFRA_PATH, tab: 'search' },
  routing: { path: AI_INFRA_PATH, tab: 'behavior' },
}

export interface OperateSettingsTarget {
  path: typeof AI_INFRA_PATH | typeof SYSTEM_CONFIG_PATH
  tab: string
}

/**
 * Resolves a System configuration deep link written against the backend tab
 * ids (`/admin/config?tab=processing&section=docling`) to the page and tab
 * that shows it now. Returns null when the link already points at its home.
 */
export function resolveConfigDeepLink(
  tab: string | undefined,
  section: string | undefined
): OperateSettingsTarget | null {
  if (!tab) return null

  if (section) {
    const ref = { tab, section }
    const aiTab = aiSectionHome.get(sectionKey(ref))
    if (aiTab) return { path: AI_INFRA_PATH, tab: aiTab }
    const systemTab = systemSectionHome.get(sectionKey(ref))
    if (systemTab && systemTab !== tab) return { path: SYSTEM_CONFIG_PATH, tab: systemTab }
  }

  return RETIRED_CONFIG_TAB_HOME[tab] ?? null
}

/** AI infrastructure tab ids used before the topic regrouping. */
export const LEGACY_AI_TAB: Readonly<Record<string, OperateSettingsTarget>> = {
  models: { path: AI_INFRA_PATH, tab: 'providers' },
  extraction: { path: AI_INFRA_PATH, tab: 'documents' },
  rerank: { path: AI_INFRA_PATH, tab: 'search' },
  'web-search': { path: SYSTEM_CONFIG_PATH, tab: 'web_search' },
}
