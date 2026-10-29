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

/** One thing the run did, in the order it did it — what the bubble shows while working and on request later. */
export interface ResearchStep {
  /** `brief`, `plan`, `search`, `source`, `point`, `round`, `writing`, `section`, `checking`, `check` or a terminal status. */
  kind: string
  /** The words the row quotes: the query, the source title or host, the section title, the goal. */
  text: string
  /** A source's `found`/`read`/`blocked`, a point's `answered`/`not_confirmed`/`open`; null otherwise. */
  status: string | null
  /** The source or point id, so a later frame updates the row instead of adding a second one. */
  ref: string | null
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
  /** Everything the run did so far, oldest first; empty when the progress came from the run summary. */
  steps: ResearchStep[]
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
    steps: [],
    lastSeq: 0,
  }
}

/** The last thing the run did — the line the bubble shows while it works. */
export function currentStep(progress: ResearchProgress): ResearchStep | null {
  return progress.steps.length > 0 ? progress.steps[progress.steps.length - 1] : null
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

/** Every frame of an events response applied in order. */
export function applyResearchSse(state: ResearchProgress, body: string): ResearchProgress {
  let next = state
  for (const frame of parseResearchSse(body)) next = applyResearchFrame(next, frame)
  return next
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
    steps: state.steps.map((step) => ({ ...step })),
    lastSeq: frame.seq !== null && frame.seq > state.lastSeq ? frame.seq : state.lastSeq,
  }
  if (isTerminalStatus(state.status) && frame.event !== 'check') return next
  // A frame already applied (a replayed page) must not add its step twice.
  if (frame.seq !== null && frame.seq <= state.lastSeq) return next
  next.steps = appendStep(next.steps, stepFor(frame))

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

/** The row a frame adds to the steps; null for frames that are bookkeeping only (`run`, `queued`, `question`). */
function stepFor(frame: ResearchFrame): ResearchStep | null {
  const data = frame.data
  const text = (key: string): string => (typeof data[key] === 'string' ? data[key] : '')
  switch (frame.event) {
    case 'brief':
      return { kind: 'brief', text: text('goal'), status: null, ref: null }
    case 'plan':
      return { kind: 'plan', text: String(readPoints(data.points).length), status: null, ref: null }
    case 'search':
      return { kind: 'search', text: text('query'), status: text('scope') || null, ref: null }
    case 'source': {
      const id = text('id')
      if (id === '') return null
      return {
        kind: 'source',
        text: text('title') || text('host') || text('url'),
        status: text('status') || 'found',
        ref: id,
      }
    }
    case 'point': {
      const id = text('id')
      if (id === '') return null
      return { kind: 'point', text: text('title'), status: text('status') || 'open', ref: id }
    }
    case 'round': {
      const n = numberOrNull(data.n)
      const max = numberOrNull(data.max)
      return {
        kind: 'round',
        text: n !== null && max !== null ? `${n}/${max}` : '',
        status: null,
        ref: null,
      }
    }
    case 'writing':
      return { kind: 'writing', text: text('section'), status: null, ref: null }
    case 'section':
      return { kind: 'section', text: text('title'), status: null, ref: null }
    case 'checking':
      return { kind: 'checking', text: '', status: null, ref: null }
    case 'check': {
      const check = readCheck(data) ?? { supported: 0, statements: 0 }
      return {
        kind: 'check',
        text: `${check.supported}/${check.statements}`,
        status: null,
        ref: null,
      }
    }
    default:
      if (isTerminalStatus(frame.event)) {
        const outcome = data.outcome
        const code =
          outcome &&
          typeof outcome === 'object' &&
          typeof (outcome as { code?: unknown }).code === 'string'
            ? (outcome as { code: string }).code
            : ''
        return { kind: frame.event, text: code, status: null, ref: null }
      }
      return null
  }
}

/** A source or point frame updates its earlier row; everything else is a new row. */
function appendStep(steps: ResearchStep[], step: ResearchStep | null): ResearchStep[] {
  if (step === null) return steps
  if (step.ref !== null) {
    const index = steps.findIndex((row) => row.kind === step.kind && row.ref === step.ref)
    if (index >= 0) {
      const kept = steps[index]
      steps[index] = { ...kept, status: step.status, text: step.text || kept.text }
      return steps
    }
  }
  steps.push(step.kind === 'source' && step.text === '' ? { ...step, text: step.ref ?? '' } : step)
  return steps
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
