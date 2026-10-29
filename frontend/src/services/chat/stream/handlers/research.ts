/**
 * Research run updates. The events endpoint returns the frames recorded so
 * far and then closes, so the card applies each frame and asks again.
 */

export interface ResearchPoint {
  id: string
  title: string
  status: string
  /** Why the plan has this point, in the model's words; empty when the plan did not say. */
  why?: string
  /** What the lead last said about the point when it set the status. */
  note?: string
}

export interface ResearchSnippet {
  title: string
  quote: string
}

/** One thing the run did, in the order it did it — what the bubble shows while working and on request later. */
export interface ResearchStep {
  /**
   * `brief`, `plan`, `thought`, `search`, `source`, `finding`, `point`, `round`, `writing`,
   * `section`, `checking`, `verdict`, `check` or a terminal status.
   */
  kind: string
  /**
   * The words the row quotes: the query, the source title or host, the section title, the goal,
   * the model's own sentence (`thought`), the fact it took from a page (`finding`), the statement
   * the fact-check changed (`verdict`).
   */
  text: string
  /**
   * A source's `found`/`reading`/`read`/`blocked`, a point's `answered`/`not_confirmed`/`open`,
   * a search's scope (`web`/`files`) or `skipped`, a round's decision (`round`/`write`/`wait_answer`),
   * a verdict's `partial`/`unsupported`/`contradicted`/`removed`; null otherwise.
   */
  status: string | null
  /** The source or point id, so a later frame updates the row instead of adding a second one. */
  ref: string | null
  /** For `search`: how many results it returned. For a read `source`: how many findings it gave. */
  count?: number
  /**
   * The model's reason in one sentence: why a source was picked or could not be read, what the
   * lead said about a point, why a round ended, why the fact-check changed a statement.
   */
  note?: string
  /** For `finding`: the words of the page that back the fact, when the reader quoted them. */
  quote?: string
  /** For `finding`: the source it came from, so the row can name it. */
  source?: string
  /** For `check`: what the fact-check left out, and whether a model of the same provider checked. */
  check?: { removed: number; sameProvider: boolean }
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
  /** The report as the writer is writing it, piece by piece; the fact-check may still change it. */
  draft: string
  /** The lead's note as it is still writing it; cleared when the finished note (`thought`) arrives. */
  thinking: string
  /** The title of every source the run named, by id, so a finding can say where it came from. */
  sourceTitles: Record<string, string>
  /** When the run started (the server's stamp, or when this client first saw it); null when unknown. */
  startedAt: number | null
  lastSeq: number
}

export interface ResearchFrame {
  seq: number | null
  event: string
  data: Record<string, unknown>
}

const TERMINAL = new Set(['complete', 'stopped', 'failed'])
/** Frames that say nothing about the lead's turn and leave its note being written alone. */
const LIVE_ONLY = new Set(['queued', 'ping', 'draft'])

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
    draft: '',
    thinking: '',
    sourceTitles: {},
    startedAt: Date.now(),
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
    sourceTitles: { ...state.sourceTitles },
    snippets: state.snippets.map((snippet) => ({ ...snippet })),
    steps: state.steps.map((step) => ({ ...step })),
    lastSeq: frame.seq !== null && frame.seq > state.lastSeq ? frame.seq : state.lastSeq,
  }
  if (isTerminalStatus(state.status) && frame.event !== 'check') return next
  // A frame already applied (a replayed page) must not add its step twice.
  if (frame.seq !== null && frame.seq <= state.lastSeq) return next
  if (frame.event === 'thinking') {
    next.thinking = state.thinking + (typeof frame.data.text === 'string' ? frame.data.text : '')
    return next
  }
  // Any other step means the lead's turn is over: its finished note, if any, is a step of its own.
  if (!LIVE_ONLY.has(frame.event)) next.thinking = ''
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
    if (id !== '') {
      next.sourceStatus[id] = status
      const title = typeof frame.data.title === 'string' ? frame.data.title : ''
      if (title !== '') next.sourceTitles[id] = title
    }
    return next
  }
  if (frame.event === 'draft') {
    // The writer's words, piece by piece. The phase stays `writing`.
    next.draft = state.draft + (typeof frame.data.text === 'string' ? frame.data.text : '')
    next.phase = 'writing'
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
    created_at?: string | null
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
  progress.startedAt = parseStamp(run.created_at)
  progress.points = readPoints(plan).map((point) => ({
    ...point,
    status: point.status || 'open',
  }))
  progress.sourceStatus = readSourceStatus(run.sources)
  progress.sourceTitles = readSourceTitles(run.sources)
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

/**
 * The rows that carry the model's own words: its sentence before a turn, a
 * fact a page gave with the quote behind it, and the check's verdict on one
 * statement with the checker's sentence (or the draft check's code, put into
 * words by the client).
 */
function wordsStepFor(
  kind: 'thought' | 'finding' | 'verdict',
  text: (key: string) => string
): ResearchStep | null {
  if (kind === 'thought') {
    const words = text('text')
    return words === '' ? null : { kind, text: words, status: null, ref: null }
  }
  if (kind === 'finding') {
    const fact = text('fact')
    if (fact === '') return null
    const step: ResearchStep = {
      kind,
      text: fact,
      status: text('confidence') || null,
      ref: text('point') || null,
    }
    const quote = text('quote')
    if (quote !== '') step.quote = quote
    const source = text('source')
    if (source !== '') step.source = source
    return step
  }
  const statement = text('statement')
  if (statement === '') return null
  const step: ResearchStep = {
    kind,
    text: statement,
    status: text('verdict') || 'unsupported',
    ref: null,
  }
  const note = text('note') || text('reason')
  if (note !== '') step.note = note
  return step
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
    case 'thought':
    case 'finding':
    case 'verdict':
      return wordsStepFor(frame.event, text)
    case 'search': {
      const results = numberOrNull(data.results)
      const step: ResearchStep = {
        kind: 'search',
        text: text('query'),
        status: data.kept === false ? 'skipped' : text('scope') || null,
        ref: null,
      }
      if (results !== null) step.count = results
      return step
    }
    case 'source': {
      const id = text('id')
      if (id === '') return null
      const step: ResearchStep = {
        kind: 'source',
        text: text('title') || text('host') || text('url'),
        status: text('status') || 'found',
        ref: id,
      }
      // Why it could not be read, or why the sorter picked it.
      const reason = text('reason') || text('why')
      if (reason !== '') step.note = reason
      const findings = numberOrNull(data.findings)
      if (findings !== null) step.count = findings
      return step
    }
    case 'point': {
      const id = text('id')
      if (id === '') return null
      const step: ResearchStep = {
        kind: 'point',
        text: text('title'),
        status: text('status') || 'open',
        ref: id,
      }
      const note = text('note')
      if (note !== '') step.note = note
      return step
    }
    case 'round': {
      const n = numberOrNull(data.n)
      const max = numberOrNull(data.max)
      const step: ResearchStep = {
        kind: 'round',
        text: n !== null && max !== null ? `${n}/${max}` : '',
        status: text('next') || null,
        ref: null,
      }
      const reason = text('reason')
      if (reason !== '') step.note = reason
      return step
    }
    case 'writing':
      return { kind: 'writing', text: text('section'), status: null, ref: null }
    case 'section':
      return { kind: 'section', text: text('title'), status: null, ref: null }
    case 'checking':
      return { kind: 'checking', text: '', status: null, ref: null }
    case 'check': {
      const check = readCheck(data) ?? { supported: 0, statements: 0, removed: 0 }
      return {
        kind: 'check',
        text: `${check.supported}/${check.statements}`,
        status: null,
        ref: null,
        check: { removed: check.removed, sameProvider: data.same_provider === true },
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
      if (step.note !== undefined) steps[index].note = step.note
      if (step.count !== undefined) steps[index].count = step.count
      return steps
    }
  }
  steps.push(step.kind === 'source' && step.text === '' ? { ...step, text: step.ref ?? '' } : step)
  return steps
}

function mergePoints(current: ResearchPoint[], incoming: ResearchPoint[]): ResearchPoint[] {
  const known = new Map(current.map((point) => [point.id, point]))
  return incoming.map((point) => {
    const merged: ResearchPoint = {
      id: point.id,
      title: point.title,
      status: known.get(point.id)?.status ?? 'open',
    }
    if (point.why !== undefined) merged.why = point.why
    const note = known.get(point.id)?.note
    if (note !== undefined) merged.note = note
    return merged
  })
}

function applyPoint(points: ResearchPoint[], data: Record<string, unknown>): ResearchPoint[] {
  const id = typeof data.id === 'string' ? data.id : ''
  if (id === '') return points
  const status = typeof data.status === 'string' ? data.status : 'open'
  const title = typeof data.title === 'string' ? data.title : ''
  const note = typeof data.note === 'string' ? data.note : ''
  const existing = points.find((point) => point.id === id)
  if (!existing) {
    const added: ResearchPoint = { id, title: title || id, status }
    if (note !== '') added.note = note
    return [...points, added]
  }
  return points.map((point) => {
    if (point.id !== id) return point
    const updated: ResearchPoint = { ...point, status, title: title || point.title }
    if (note !== '') updated.note = note
    return updated
  })
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
    const point: ResearchPoint = {
      id,
      title,
      status: typeof row.status === 'string' ? row.status : 'open',
    }
    if (typeof row.why === 'string' && row.why !== '') point.why = row.why
    points.push(point)
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

function readSourceTitles(value: unknown): Record<string, string> {
  if (!Array.isArray(value)) return {}
  const titles: Record<string, string> = {}
  for (const item of value) {
    if (!item || typeof item !== 'object') continue
    const row = item as Record<string, unknown>
    if (typeof row.id === 'string' && typeof row.title === 'string') titles[row.id] = row.title
  }
  return titles
}

function readSnippets(sources: unknown, evidence: unknown): ResearchSnippet[] {
  const titles = new Map(Object.entries(readSourceTitles(sources)))
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

function parseStamp(value: string | null | undefined): number | null {
  if (typeof value !== 'string' || value === '') return null
  const parsed = Date.parse(value)
  return Number.isFinite(parsed) ? parsed : null
}
