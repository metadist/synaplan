import type { CapacityServer } from '@/services/api/capacityApi'

/**
 * Pure readings of a server's capacity report for the capacity page. Every
 * value here comes from the watchdog's facts; nothing is guessed.
 */

/** A web server's card, with the background roles that share its machine. */
export interface ServerGroup {
  server: CapacityServer
  helpers: CapacityServer[]
}

/** The share of AI answer slots a server must reach today to get a sizing hint. */
export const SIZING_HINT_RATIO = 0.8

const BYTE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB'] as const

export function formatBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B'
  const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), BYTE_UNITS.length - 1)
  const value = bytes / 1024 ** exponent
  const digits = exponent === 0 ? 0 : value >= 10 ? 1 : 2
  return `${value.toFixed(digits)} ${BYTE_UNITS[exponent]}`
}

/** 0..100, clamped; 0 when the total is unknown. */
export function ratioPercent(used: number, total: number): number {
  if (total <= 0 || used <= 0) return 0
  return Math.min(100, Math.round((used / total) * 100))
}

/** Whole seconds between a report time and now; null when the time is unreadable. */
export function secondsSince(iso: string, now: Date = new Date()): number | null {
  const at = Date.parse(iso)
  if (Number.isNaN(at)) return null
  return Math.max(0, Math.round((now.getTime() - at) / 1000))
}

/**
 * The one command that shows this server's log lines, built from facts the
 * watchdog reported. Null when the collector is unknown or is a bare process
 * whose service manager the watchdog cannot name.
 */
export function logCommand(server: CapacityServer): string | null {
  switch (server.logs.collector) {
    case 'docker':
      return server.hostname ? `docker logs ${server.hostname}` : null
    case 'kubernetes': {
      const pod = server.place.pod || server.hostname
      if (!pod) return null
      const namespace = server.place.namespace ? ` -n ${server.place.namespace}` : ''
      const container = server.place.container ? ` -c ${server.place.container}` : ''
      return `kubectl logs${namespace} ${pod}${container}`
    }
    default:
      return null
  }
}

/** Short role suffixes the platform compose appends to a server id. */
const HELPER_SUFFIXES = ['-worker', '-bulk', '-scheduler']

function helperBase(id: string): string | null {
  for (const suffix of HELPER_SUFFIXES) {
    if (id.endsWith(suffix) && id.length > suffix.length) {
      return id.slice(0, -suffix.length)
    }
  }
  return null
}

/**
 * Web servers become cards; workers and the scheduler attach to the web
 * server they share a machine with (same `place.host`, or the id prefix the
 * compose file gives them). A helper with no web server of its own is a
 * card as well, so nothing reported is hidden.
 */
export function groupServers(servers: CapacityServer[]): ServerGroup[] {
  const web = servers.filter((s) => s.role === 'web')
  const helpers = servers.filter((s) => s.role !== 'web')
  const groups: ServerGroup[] = web.map((server) => ({ server, helpers: [] }))
  const orphans: CapacityServer[] = []

  for (const helper of helpers) {
    const byHost = helper.place.host
      ? groups.find((g) => g.server.place.host === helper.place.host)
      : undefined
    const base = helperBase(helper.id)
    const byId = base ? groups.find((g) => g.server.id === base) : undefined
    const home = byHost ?? byId
    if (home) {
      home.helpers.push(helper)
    } else {
      orphans.push(helper)
    }
  }

  for (const group of groups) {
    group.helpers.sort((a, b) => a.id.localeCompare(b.id))
  }
  groups.sort((a, b) => a.server.id.localeCompare(b.server.id))
  return [...groups, ...orphans.map((server) => ({ server, helpers: [] }))]
}

/** True when today's peak reached the sizing-hint share of the AI answer slots. */
export function needsSizingHint(server: CapacityServer): boolean {
  const total = server.peak_today.streams_total || server.streams.total
  if (total <= 0) return false
  return server.peak_today.streams_busy / total >= SIZING_HINT_RATIO
}
