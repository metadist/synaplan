import {
  isTerminalStatus,
  sourceCounts,
  type ResearchProgress,
  type ResearchStep,
} from '@/services/chat/stream/handlers/research'

/** vue-i18n's `t` with the plural form chosen by the third argument. */
type Translate = (key: string, params?: Record<string, unknown>, plural?: number) => string

/** The five things a research does, in the order a person sees them. */
export type ResearchPhaseId = 'understand' | 'plan' | 'gather' | 'write' | 'check'

export type ResearchPhaseState = 'done' | 'active' | 'upcoming'

export interface ResearchPhase {
  id: ResearchPhaseId
  state: ResearchPhaseState
  /** The rows the phase opens to, oldest first. Empty for a phase still to come. */
  rows: ResearchStep[]
}

export const PHASE_ORDER: ResearchPhaseId[] = ['understand', 'plan', 'gather', 'write', 'check']

const PHASE_OF: Record<string, ResearchPhaseId> = {
  brief: 'understand',
  plan: 'plan',
  point: 'plan',
  search: 'gather',
  source: 'gather',
  round: 'gather',
  writing: 'write',
  section: 'write',
  checking: 'check',
  check: 'check',
}

/**
 * The run as phases a person can follow. A phase the run has passed is
 * done and folds to one line, the phase it is in is active and open, the
 * phases still to come are listed muted so the road ahead is visible. A
 * phase the run never entered is left out once the run has ended, and a
 * run that has not reported anything yet is understanding the question.
 */
export function researchPhases(progress: ResearchProgress): ResearchPhase[] {
  const running = !isTerminalStatus(progress.status)
  const grouped = new Map<ResearchPhaseId, ResearchStep[]>()
  for (const step of progress.steps) {
    const id = PHASE_OF[step.kind]
    if (id === undefined) continue
    const rows = grouped.get(id) ?? []
    rows.push(step)
    grouped.set(id, rows)
  }
  const reached = PHASE_ORDER.filter((id) => grouped.has(id))
  const current: ResearchPhaseId = reached.length > 0 ? reached[reached.length - 1] : 'understand'

  const phases: ResearchPhase[] = []
  for (const id of PHASE_ORDER) {
    const steps = grouped.get(id)
    if (steps !== undefined) {
      phases.push({
        id,
        state: running && id === current ? 'active' : 'done',
        rows: phaseRows(id, steps, progress),
      })
      continue
    }
    if (!running) continue
    const position = PHASE_ORDER.indexOf(id)
    const currentPosition = PHASE_ORDER.indexOf(current)
    if (position === currentPosition) phases.push({ id, state: 'active', rows: [] })
    else if (position > currentPosition) phases.push({ id, state: 'upcoming', rows: [] })
  }
  return phases
}

/** The rows a phase opens to. The plan shows its points with their current state, not the frames that set them. */
function phaseRows(
  id: ResearchPhaseId,
  steps: ResearchStep[],
  progress: ResearchProgress
): ResearchStep[] {
  if (id === 'plan') {
    return progress.points.map((point) => ({
      kind: 'point',
      text: point.title,
      status: point.status,
      ref: point.id,
    }))
  }
  return steps
}

/** One muted sentence under the phase title: what it found out or how far it is. */
export function phaseSummary(
  t: Translate,
  phase: ResearchPhase,
  progress: ResearchProgress
): string {
  switch (phase.id) {
    case 'understand':
      return phase.rows.find((row) => row.kind === 'brief')?.text ?? ''
    case 'plan': {
      const count = progress.points.length
      if (count === 0) return ''
      const answered = progress.points.filter((point) => point.status === 'answered').length
      return [
        t('research.phase.points', { count }, count),
        t('research.phase.answered', { count: answered }),
      ].join(' · ')
    }
    case 'gather': {
      const searches = phase.rows.filter(
        (row) => row.kind === 'search' && row.status !== 'skipped'
      ).length
      const counts = sourceCounts(progress)
      if (searches === 0 && counts.found === 0) return ''
      const parts = [
        t('research.phase.searches', { count: searches }, searches),
        t('research.phase.sourcesRead', { count: counts.read }, counts.read),
      ]
      if (counts.blocked > 0) {
        parts.push(t('research.phase.gatherBlocked', { count: counts.blocked }))
      }
      return parts.join(' · ')
    }
    case 'write': {
      const written = phase.rows.filter((row) => row.kind === 'section').length
      if (written > 0) return t('research.phase.sectionsWritten', { count: written }, written)
      return phase.rows.find((row) => row.kind === 'writing')?.text ?? ''
    }
    case 'check': {
      const check = progress.check
      if (check === null || phase.state === 'active') return ''
      const parts = [t('research.step.check', { ratio: `${check.supported}/${check.statements}` })]
      if (check.removed > 0) parts.push(t('research.step.checkRemoved', { count: check.removed }))
      return parts.join(' ')
    }
  }
}

/** `m:ss` for a run that takes minutes. */
export function formatElapsed(ms: number): string {
  const total = Math.max(0, Math.floor(ms / 1000))
  const minutes = Math.floor(total / 60)
  const seconds = total % 60
  return `${minutes}:${seconds.toString().padStart(2, '0')}`
}
