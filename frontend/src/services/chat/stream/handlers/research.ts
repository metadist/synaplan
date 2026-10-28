/**
 * Research run updates. The events endpoint returns the frames recorded so
 * far and then closes, so the card applies each frame and asks again.
 */

export interface ResearchPoint {
  id: string
  title: string
  status: string
}

export interface ResearchSnippet {
  title: string
  quote: string
}

export interface ResearchProgress {
  id: string
  chatId: number
  question: string
  status: string
  phase: string
  points: ResearchPoint[]
  sourceStatus: Record<string, string>
  snippets: ResearchSnippet[]
  check: { statements: number; supported: number; removed: number } | null
  queuedSeconds: number | null
  /** How far an automatic run decided to look once it had read the question: `quick` or `standard`. */
  ceiling: string | null
  /** Why a finished run ended the way it did (`answered_without_research`, …). */
  outcome: string | null
  lastSeq: number
}

export interface ResearchFrame {
  seq: number | null
  event: string
  data: Record<string, unknown>
}

const TERMINAL = new Set(['complete', 'stopped', 'failed'])

export function isTerminalStatus(status: string): boolean {
  return TERMINAL.has(status)
}

export function emptyProgress(id: string, chatId: number, question: string): ResearchProgress {
  return {
    id,
    chatId,
    question,
    status: 'briefing',
    phase: 'run',
    points: [],
    sourceStatus: {},
    snippets: [],
    check: null,
    queuedSeconds: null,
    ceiling: null,
    outcome: null,
    lastSeq: 0,
  }
}

export function sourceCounts(progress: ResearchProgress): {
  found: number
  read: number
  blocked: number
} {
  const values = Object.values(progress.sourceStatus)
  return {
    found: values.length,
    read: values.filter((status) => status === 'read').length,
    blocked: values.filter((status) => status === 'blocked').length,
  }
}

export function parseResearchSse(body: string): ResearchFrame[] {
  const frames: ResearchFrame[] = []
  for (const block of body.split('\n\n')) {
    if (block.trim() === '') continue
    let seq: number | null = null
    let event = 'message'
    let data: Record<string, unknown> = {}
    for (const line of block.split('\n')) {
      if (line.startsWith('id:')) {
        const parsed = Number(line.slice(3).trim())
        seq = Number.isFinite(parsed) ? parsed : null
      } else if (line.startsWith('event:')) {
        event = line.slice(6).trim()
      } else if (line.startsWith('data:')) {
        try {
          const parsed: unknown = JSON.parse(line.slice(5).trim())
          if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
            data = parsed as Record<string, unknown>
          }
        } catch {
          data = {}
        }
      }
    }
    frames.push({ seq, event, data })
  }
  return frames
}

export function applyResearchFrame(
  state: ResearchProgress,
  frame: ResearchFrame
): ResearchProgress {
  const next: ResearchProgress = {
    ...state,
    points: state.points.map((point) => ({ ...point })),
    sourceStatus: { ...state.sourceStatus },
    snippets: state.snippets.map((snippet) => ({ ...snippet })),
    lastSeq: frame.seq !== null && frame.seq > state.lastSeq ? frame.seq : state.lastSeq,
  }
  if (isTerminalStatus(state.status) && frame.event !== 'check') return next

  if (frame.event === 'plan') {
    next.phase = 'plan'
    next.points = mergePoints(state.points, readPoints(frame.data.points))
    next.ceiling = typeof frame.data.ceiling === 'string' ? frame.data.ceiling : state.ceiling
    return next
  }
  if (frame.event === 'point') {
    next.phase = 'point'
    next.points = applyPoint(next.points, frame.data)
    return next
  }
  if (frame.event === 'source') {
    next.phase = 'source'
    const id = typeof frame.data.id === 'string' ? frame.data.id : ''
    const status = typeof frame.data.status === 'string' ? frame.data.status : 'found'
    if (id !== '') next.sourceStatus[id] = status
    return next
  }
  if (frame.event === 'check') {
    next.phase = 'check'
    next.check = readCheck(frame.data)
    return next
  }
  if (frame.event === 'queued') {
    next.phase = 'queued'
    next.queuedSeconds = numberOrNull(frame.data.since_seconds)
    return next
  }
  if (isTerminalStatus(frame.event)) {
    next.status = frame.event
    next.phase = frame.event
    return next
  }
  next.phase = frame.event
  return next
}

export function progressFromRun(
  chatId: number,
  run: {
    id: string
    status: string
    question: string
    queued_since?: number | null
    plan?: unknown
    sources?: unknown
    evidence?: unknown
    check?: { statements?: number; supported?: number; removed?: number } | null
    ceiling?: string | null
    outcome?: { code?: string } | null
  }
): ResearchProgress {
  const plan =
    run.plan && typeof run.plan === 'object' && 'points' in run.plan
      ? (run.plan as { points?: unknown }).points
      : []
  const progress = emptyProgress(run.id, chatId, run.question)
  progress.status = run.status
  progress.phase = run.status
  progress.queuedSeconds = run.queued_since ?? null
  progress.ceiling = run.ceiling ?? null
  progress.outcome = run.outcome?.code ?? null
  progress.points = readPoints(plan).map((point) => ({
    ...point,
    status: point.status || 'open',
  }))
  progress.sourceStatus = readSourceStatus(run.sources)
  progress.snippets = readSnippets(run.sources, run.evidence)
  progress.check = run.check
    ? {
        statements: run.check.statements ?? 0,
        supported: run.check.supported ?? 0,
        removed: run.check.removed ?? 0,
      }
    : null
  return progress
}

function mergePoints(current: ResearchPoint[], incoming: ResearchPoint[]): ResearchPoint[] {
  const status = new Map(current.map((point) => [point.id, point.status]))
  return incoming.map((point) => ({
    id: point.id,
    title: point.title,
    status: status.get(point.id) ?? 'open',
  }))
}

function applyPoint(points: ResearchPoint[], data: Record<string, unknown>): ResearchPoint[] {
  const id = typeof data.id === 'string' ? data.id : ''
  if (id === '') return points
  const status = typeof data.status === 'string' ? data.status : 'open'
  const title = typeof data.title === 'string' ? data.title : ''
  const existing = points.find((point) => point.id === id)
  if (!existing) {
    return [...points, { id, title: title || id, status }]
  }
  return points.map((point) =>
    point.id === id ? { ...point, status, title: title || point.title } : point
  )
}

function readPoints(value: unknown): ResearchPoint[] {
  if (!Array.isArray(value)) return []
  const points: ResearchPoint[] = []
  for (const item of value) {
    if (!item || typeof item !== 'object') continue
    const row = item as Record<string, unknown>
    const id = typeof row.id === 'string' ? row.id : ''
    const title = typeof row.title === 'string' ? row.title : ''
    if (id === '' || title === '') continue
    points.push({
      id,
      title,
      status: typeof row.status === 'string' ? row.status : 'open',
    })
  }
  return points
}

function readCheck(data: Record<string, unknown>): ResearchProgress['check'] {
  return {
    statements: numberOrNull(data.statements) ?? 0,
    supported: numberOrNull(data.supported) ?? 0,
    removed: numberOrNull(data.removed) ?? 0,
  }
}

function readSourceStatus(value: unknown): Record<string, string> {
  if (!Array.isArray(value)) return {}
  const status: Record<string, string> = {}
  for (const item of value) {
    if (!item || typeof item !== 'object') continue
    const row = item as Record<string, unknown>
    if (typeof row.id !== 'string') continue
    status[row.id] = typeof row.status === 'string' ? row.status : 'found'
  }
  return status
}

function readSnippets(sources: unknown, evidence: unknown): ResearchSnippet[] {
  const titles = new Map<string, string>()
  if (Array.isArray(sources)) {
    for (const item of sources) {
      if (!item || typeof item !== 'object') continue
      const row = item as Record<string, unknown>
      if (typeof row.id === 'string' && typeof row.title === 'string') titles.set(row.id, row.title)
    }
  }
  if (!Array.isArray(evidence)) return []
  const snippets: ResearchSnippet[] = []
  for (const item of evidence) {
    if (snippets.length >= 3 || !item || typeof item !== 'object') continue
    const row = item as Record<string, unknown>
    const sourceId = typeof row.source === 'string' ? row.source : ''
    const quotes = Array.isArray(row.quotes) ? row.quotes : []
    const first = quotes.find((quote) => quote && typeof quote === 'object') as
      Record<string, unknown> | undefined
    const text = first && typeof first.text === 'string' ? first.text : ''
    if (text === '') continue
    snippets.push({ title: titles.get(sourceId) || sourceId, quote: text })
  }
  return snippets
}

function numberOrNull(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}
