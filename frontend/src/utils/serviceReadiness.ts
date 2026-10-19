import type { ServiceReadiness, ServiceReadinessState } from '@/services/featuresService'

type Translate = (key: string, params?: Record<string, unknown>) => string
type HasKey = (key: string) => boolean

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
  tts: ['piper_tts', 'piper-tts'],
  collabora: ['office_convert', 'office-convert'],
}

/** Feature/module ids already reported by a service that needs attention. */
export function idsCoveredByServices(services: ServiceReadiness[] | undefined): Set<string> {
  const covered = new Set<string>()
  for (const service of (services ?? []).filter(serviceNeedsAttention)) {
    covered.add(service.id)
    for (const alias of SERVICE_ALIASES[service.id] ?? []) covered.add(alias)
  }
  return covered
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
