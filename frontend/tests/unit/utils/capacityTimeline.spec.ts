import { describe, expect, it } from 'vitest'
import {
  formatCost,
  formatDuration,
  largestPart,
  partsOverlap,
  perMinute,
  requestParts,
  share,
  sortRows,
  type TimedRequest,
} from '@/utils/capacityTimeline'

const request = (over: Partial<TimedRequest> = {}): TimedRequest => ({
  total_ms: 1000,
  php_ms: 1000,
  db_ms: 0,
  ai: [],
  tools: [],
  egress: [],
  ...over,
})

describe('formatDuration', () => {
  it.each([
    [null, '–'],
    [undefined, '–'],
    [-1, '–'],
    [Number.NaN, '–'],
    [0, '0 ms'],
    [0.4, '< 1 ms'],
    [820.4, '820 ms'],
    [1240, '1.2 s'],
    [14_400, '14 s'],
    [120_000, '2 min'],
    [125_000, '2 min 5 s'],
  ])('%s → %s', (ms, expected) => {
    expect(formatDuration(ms)).toBe(expected)
  })
})

describe('perMinute', () => {
  it('is zero without requests', () => {
    expect(perMinute(0, 600)).toBe('0')
  })

  it('keeps one decimal below ten', () => {
    expect(perMinute(45, 600)).toBe('4.5')
  })

  it('rounds from ten up', () => {
    expect(perMinute(1234, 600)).toBe('123')
  })

  it('does not divide by a zero-second window', () => {
    expect(perMinute(3, 0)).toBe('180')
  })
})

describe('share', () => {
  it('is zero without a total', () => {
    expect(share(5, 0)).toBe(0)
  })

  it('rounds to whole percent and caps at 100', () => {
    expect(share(1, 3)).toBe(33)
    expect(share(7, 5)).toBe(100)
  })
})

describe('requestParts', () => {
  const total = (parts: { ms: number }[]): number => parts.reduce((acc, p) => acc + p.ms, 0)

  it('puts everything in PHP when nothing else was timed', () => {
    const parts = requestParts(request())
    expect(parts.find((p) => p.key === 'php')?.ms).toBe(1000)
    expect(total(parts)).toBe(1000)
  })

  it('counts outbound only beyond what AI and tools explain', () => {
    const parts = requestParts(
      request({
        db_ms: 50,
        ai: [{ duration_ms: 600 }],
        tools: [{ duration_ms: 100 }],
        egress: [{ duration_ms: 650 }, { duration_ms: 150 }],
      })
    )
    const byKey = Object.fromEntries(parts.map((p) => [p.key, p.ms]))
    expect(byKey).toEqual({ wait: 0, ai: 600, tools: 100, db: 50, outbound: 100, php: 150 })
    expect(total(parts)).toBe(1000)
  })

  it('never adds up to more than the total', () => {
    const parts = requestParts(
      request({ total_ms: 300, wait_ms: 100, ai: [{ duration_ms: 900 }], db_ms: 80 })
    )
    expect(total(parts)).toBe(300)
    expect(parts.find((p) => p.key === 'db')?.ms).toBe(0)
  })

  it('ignores negative timings', () => {
    const parts = requestParts(request({ db_ms: -20, ai: [{ duration_ms: -5 }] }))
    expect(parts.find((p) => p.key === 'php')?.ms).toBe(1000)
  })
})

describe('measured parts', () => {
  it('keeps each raw duration even when the split has no room for it', () => {
    const r = request({
      total_ms: 1000,
      ai: [{ duration_ms: 600 }],
      tools: [{ duration_ms: 800 }],
    })
    const parts = requestParts(r)
    expect(parts.find((p) => p.key === 'ai')?.measured).toBe(600)
    expect(parts.find((p) => p.key === 'tools')?.measured).toBe(800)
    expect(parts.find((p) => p.key === 'tools')?.ms).toBe(400)
    expect(partsOverlap(parts, r.total_ms)).toBe(true)
  })

  it('keeps outbound calls that AI and tools do not explain in the legend', () => {
    const parts = requestParts(
      request({ ai: [{ duration_ms: 900 }], egress: [{ duration_ms: 300 }] })
    )
    expect(parts.find((p) => p.key === 'outbound')).toMatchObject({ ms: 0, measured: 300 })
  })

  it('does not report an overlap when the parts fit', () => {
    const r = request({ db_ms: 50, ai: [{ duration_ms: 600 }] })
    expect(partsOverlap(requestParts(r), r.total_ms)).toBe(false)
  })
})

describe('largestPart', () => {
  it('is null when every part is empty', () => {
    expect(largestPart([{ key: 'php', ms: 0 }])).toBeNull()
  })

  it('picks the first of equal longest parts', () => {
    expect(
      largestPart([
        { key: 'ai', ms: 400 },
        { key: 'db', ms: 400 },
        { key: 'php', ms: 200 },
      ])?.key
    ).toBe('ai')
  })
})

describe('sortRows', () => {
  const rows = [
    { name: 'b', p95: 20 },
    { name: 'a', p95: null },
    { name: 'c', p95: 90 },
    { name: 'd', p95: 20 },
  ]

  it('sorts descending, stable, nulls last', () => {
    expect(sortRows(rows, 'p95', 'desc').map((r) => r.name)).toEqual(['c', 'b', 'd', 'a'])
  })

  it('sorts ascending with nulls still last', () => {
    expect(sortRows(rows, 'p95', 'asc').map((r) => r.name)).toEqual(['b', 'd', 'c', 'a'])
  })

  it('does not change the input', () => {
    sortRows(rows, 'name', 'asc')
    expect(rows[0].name).toBe('b')
  })
})

describe('formatCost', () => {
  it('shows four decimals', () => {
    expect(formatCost('0.12')).toBe('0.1200')
  })

  it('shows zero for a value that is not a number', () => {
    expect(formatCost('n/a')).toBe('0.0000')
  })
})
