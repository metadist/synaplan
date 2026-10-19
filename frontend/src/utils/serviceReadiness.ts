import type { ServiceReadiness, ServiceReadinessState } from '@/services/featuresService'

type Translate = (key: string, params?: Record<string, unknown>, plural?: number) => string
type HasKey = (key: string) => boolean

export interface ServiceReadinessPillSummary {
  kind: 'progress' | 'attention'
  /** Count shown at 320 px (`3/6` while setting up, `1` when something stopped). */
  shortLabel: string
  /** Full pill sentence for wider screens. */
  label: string
}

export interface ServiceCopy {
  name: string
  unlocks: string
  /** What is missing while the service is not ready; null once it is ready. */
  effect: string | null
  /** Localized recovery sentence; null where the backend offers no step (e.g. Kubernetes). */
  recovery: string | null
  /** Shell commands from the backend's recovery, shown verbatim. */
  commands: string[]
}

const DOCS_URL = 'https://docs.synaplan.com/'
const BASE = 'settings.features.services'

/** "You switched this off" and "this stopped working" are different facts. */
export function serviceNeedsAttention(service: ServiceReadiness): boolean {
  return service.state === 'unavailable' || service.state === 'failed'
}

export function servicesNeedingAttention(services: ServiceReadiness[] | undefined): number {
  return (services ?? []).filter(serviceNeedsAttention).length
}

/** Feature and module rows that describe the same container as a service row. */
const SERVICE_ALIASES: Record<string, string[]> = {
  ollama: ['local_ai', 'local-ai'],
  qdrant: ['memory-service'],
  // Provider feature id is `piper`; the module id is `piper_tts`.
  tts: ['piper', 'piper_tts', 'piper-tts'],
  collabora: ['office_convert', 'office-convert'],
}

/** Progress and "you switched this off" are already on the service list. */
const SPOKEN_FOR_WITHOUT_A_PROBLEM = new Set<ServiceReadinessState>([
  'off',
  'starting',
  'downloading',
])

/**
 * Feature/module ids the service list already speaks for: a service that needs
 * attention has its own row, a switched-off one is not a problem, and a
 * starting or downloading one is progress already shown there.
 */
export function idsCoveredByServices(services: ServiceReadiness[] | undefined): Set<string> {
  return idsOf(
    (services ?? []).filter(
      (s) => serviceNeedsAttention(s) || SPOKEN_FOR_WITHOUT_A_PROBLEM.has(s.state)
    )
  )
}

/** Feature/module ids whose outage already has its own service row. */
export function idsReportedAsDown(services: ServiceReadiness[] | undefined): Set<string> {
  return idsOf((services ?? []).filter(serviceNeedsAttention))
}

function idsOf(services: ServiceReadiness[]): Set<string> {
  return new Set(services.flatMap((s) => [s.id, ...(SERVICE_ALIASES[s.id] ?? [])]))
}

export function recoveryCommands(recovery: string | null): string[] {
  if (!recovery) return []
  return [...recovery.matchAll(/`([^`]+)`/g)].map((match) => match[1])
}

function recoveryKey(service: ServiceReadiness): string | null {
  switch (service.state) {
    case 'off':
      return service.profile ? 'offProfile' : null
    case 'unavailable':
      return 'unavailable'
    case 'failed':
      return service.id === 'ollama' ? 'failedOllama' : 'failed'
    default:
      return null
  }
}

/**
 * Localizes one readiness row. The backend sends English copy as a fallback;
 * the commands inside its recovery sentence stay untranslated.
 */
export function serviceCopy(service: ServiceReadiness, t: Translate, te: HasKey): ServiceCopy {
  const item = `${BASE}.items.${service.id}`
  const pick = (key: string, fallback: string): string => (te(key) ? t(key) : fallback)
  const key = service.recovery ? recoveryKey(service) : null

  return {
    name: pick(`${item}.name`, service.name),
    unlocks: pick(`${item}.unlocks`, service.unlocks),
    effect: service.effect === null ? null : pick(`${item}.effect`, service.effect),
    recovery: key
      ? t(`${BASE}.recovery.${key}`, { profile: service.profile ?? '' })
      : service.recovery,
    commands: key ? recoveryCommands(service.recovery) : [],
  }
}

export function serviceStateLabel(service: ServiceReadiness, t: Translate): string {
  if (service.state === 'downloading' && service.percent !== null) {
    return t(`${BASE}.downloadingPercent`, { percent: service.percent })
  }
  return t(`${BASE}.state.${service.state}`)
}

/** "gpt-oss:20b · about 6 min left" — only the parts the backend knows. */
export function serviceProgress(service: ServiceReadiness, t: Translate): string | null {
  if (service.state !== 'downloading') return null
  const parts: string[] = []
  if (service.model) parts.push(service.model)
  if (service.minutes_left !== null) {
    parts.push(t(`${BASE}.minutesLeft`, { count: service.minutes_left }))
  }
  return parts.length > 0 ? parts.join(' · ') : null
}

export function serviceDocsHref(service: ServiceReadiness): string | null {
  return service.docs_anchor ? `${DOCS_URL}${service.docs_anchor.replace(/^\//, '')}` : null
}

const STATE_RANK: Record<ServiceReadinessState, number> = {
  failed: 0,
  unavailable: 1,
  downloading: 2,
  starting: 3,
  ready: 4,
  off: 5,
}

/** Problems first, then progress, then the calm rows. */
export function sortServices(services: ServiceReadiness[]): ServiceReadiness[] {
  return [...services].sort((a, b) => STATE_RANK[a.state] - STATE_RANK[b.state])
}

/** True while a service is still coming up or pulling a model. */
export function serviceIsInProgress(service: ServiceReadiness): boolean {
  return service.state === 'starting' || service.state === 'downloading'
}

/**
 * The "Still setting up" pill next to Quick model setup (plan §5.10, C15).
 *
 * Counts only running optional services (`state !== 'off'`). When a service is
 * `unavailable` or `failed`, the pill turns amber ("1 needs attention"). After
 * the first session (`progressSeen === true`), `starting` and `downloading` no
 * longer show the pill — only `unavailable` or `failed` bring it back.
 */
export function serviceReadinessPillSummary(
  services: ServiceReadiness[] | undefined,
  t: Translate,
  progressSeen = false
): ServiceReadinessPillSummary | null {
  const running = (services ?? []).filter((s) => s.state !== 'off')
  if (running.length === 0) return null

  const attentionCount = running.filter(serviceNeedsAttention).length
  if (attentionCount > 0) {
    return {
      kind: 'attention',
      shortLabel: String(attentionCount),
      label: t(`${BASE}.pill.attention`, { count: attentionCount }, attentionCount),
    }
  }

  if (progressSeen) return null
  const inProgress = running.some(serviceIsInProgress)
  if (!inProgress) return null

  const ready = running.filter((s) => s.state === 'ready').length
  const total = running.length
  return {
    kind: 'progress',
    shortLabel: `${ready}/${total}`,
    label: t(`${BASE}.pill.progress`, { ready, total }),
  }
}

/**
 * Status badge for the Ollama tile on the welcome screen (plan §5.10, C15),
 * read from the same `services` list as `/setup/status`.
 */
export function ollamaServiceBadge(
  services: ServiceReadiness[] | undefined,
  t: Translate
): string | null {
  const ollama = (services ?? []).find((s) => s.id === 'ollama')
  if (!ollama || ollama.state === 'off') return null
  return serviceStateLabel(ollama, t)
}
