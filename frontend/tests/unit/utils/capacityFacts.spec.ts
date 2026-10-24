import { describe, expect, it } from 'vitest'
import type { CapacityServer } from '@/services/api/capacityApi'
import {
  formatBytes,
  groupServers,
  logCommand,
  needsSizingHint,
  ratioPercent,
  secondsSince,
} from '@/utils/capacityFacts'

function server(overrides: Partial<CapacityServer> & { id: string }): CapacityServer {
  return {
    hostname: overrides.id,
    role: 'web',
    runtime: 'compose',
    version: 'dev',
    watchdog_version: '0.1.0',
    reporting: true,
    updated_at: '2026-10-24T19:00:00Z',
    last_seen_at: '2026-10-24T19:00:00Z',
    php_ready: true,
    admin_ok: true,
    streams: { total: 16, busy: 4 },
    api: { total: 8, busy: 1 },
    memory: { rss_bytes: 0, limit_bytes: 0, limited: false },
    cpu: { cores: 8, limited: true, usage_ratio: 0.1 },
    place: {
      host: '',
      pod: '',
      namespace: '',
      deployment: '',
      container: '',
      cpu_request: '',
      memory_limit: '',
    },
    logs: { collector: 'docker', event_ring_host: overrides.id },
    threads: { count: 24, busy: 3 },
    peak_today: { streams_busy: 0, streams_total: 16, at: null },
    ...overrides,
  }
}

describe('capacityFacts', () => {
  it('formats bytes for a person', () => {
    expect(formatBytes(0)).toBe('0 B')
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(2.1 * 1024 ** 3)).toBe('2.10 GB')
    expect(formatBytes(491044864)).toBe('468.3 MB')
  })

  it('clamps a ratio to a whole percent', () => {
    expect(ratioPercent(14, 24)).toBe(58)
    expect(ratioPercent(0, 24)).toBe(0)
    expect(ratioPercent(30, 24)).toBe(100)
    expect(ratioPercent(3, 0)).toBe(0)
  })

  it('counts seconds since a report', () => {
    const now = new Date('2026-10-24T19:03:00Z')
    expect(secondsSince('2026-10-24T19:00:00Z', now)).toBe(180)
    expect(secondsSince('not a time', now)).toBeNull()
  })

  it('builds the log command from facts and never guesses', () => {
    expect(logCommand(server({ id: 'web1', hostname: '79e4f90bc8c6' }))).toBe(
      'docker logs 79e4f90bc8c6'
    )
    expect(
      logCommand(
        server({
          id: 'synaplan-web-7d9f-x2',
          logs: { collector: 'kubernetes', event_ring_host: 'synaplan-web-7d9f-x2' },
          place: {
            host: 'node-a',
            pod: 'synaplan-web-7d9f-x2',
            namespace: 'synaplan',
            deployment: 'synaplan-web',
            container: 'synaplan',
            cpu_request: '1',
            memory_limit: '3Gi',
          },
        })
      )
    ).toBe('kubectl logs -n synaplan synaplan-web-7d9f-x2 -c synaplan')
    expect(
      logCommand(server({ id: 'bare', logs: { collector: 'process', event_ring_host: 'bare' } }))
    ).toBeNull()
  })

  it('attaches workers to the web server of their machine or id', () => {
    const groups = groupServers([
      server({ id: 'web2' }),
      server({ id: 'web1-worker', role: 'worker' }),
      server({ id: 'web1' }),
      server({
        id: 'pod-worker-abc',
        role: 'worker',
        place: {
          host: 'node-a',
          pod: 'pod-worker-abc',
          namespace: 'ns',
          deployment: 'synaplan-worker',
          container: 'synaplan',
          cpu_request: '',
          memory_limit: '',
        },
      }),
      server({
        id: 'pod-web-xyz',
        place: {
          host: 'node-a',
          pod: 'pod-web-xyz',
          namespace: 'ns',
          deployment: 'synaplan-web',
          container: 'synaplan',
          cpu_request: '',
          memory_limit: '',
        },
      }),
      server({ id: 'lonely-scheduler', role: 'scheduler' }),
    ])

    expect(groups.map((g) => g.server.id)).toEqual([
      'pod-web-xyz',
      'web1',
      'web2',
      'lonely-scheduler',
    ])
    expect(groups[0].helpers.map((h) => h.id)).toEqual(['pod-worker-abc'])
    expect(groups[1].helpers.map((h) => h.id)).toEqual(['web1-worker'])
    expect(groups[2].helpers).toEqual([])
  })

  it('offers the sizing hint from today’s peak only', () => {
    expect(
      needsSizingHint(
        server({ id: 'a', peak_today: { streams_busy: 22, streams_total: 24, at: 'x' } })
      )
    ).toBe(true)
    expect(
      needsSizingHint(
        server({ id: 'b', peak_today: { streams_busy: 10, streams_total: 24, at: 'x' } })
      )
    ).toBe(false)
    expect(
      needsSizingHint(
        server({
          id: 'c',
          streams: { total: 0, busy: 0 },
          peak_today: { streams_busy: 0, streams_total: 0, at: null },
        })
      )
    ).toBe(false)
  })
})
