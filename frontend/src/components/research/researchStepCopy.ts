import type { ResearchPoint, ResearchStep } from '@/services/chat/stream/handlers/research'

/** vue-i18n's `t` with the plural form chosen by the third argument. */
type Translate = (key: string, params?: Record<string, unknown>, plural?: number) => string

/** One step as a row: what was done, the words it concerned, and the model's own sentence about it. */
export interface ResearchStepCopy {
  /** The verb (`Searched`, `Read`), or for a thought the model's sentence itself. */
  title: string
  /** The query, source or section it concerned; quoted beside the title. */
  detail: string
  /**
   * The model's reason or words in one line under the row: why a source was picked, what a
   * page said, what the lead noted on a point, why the fact-check changed a statement.
   */
  aside: string
}

/** The reasons the draft check names by code, and the rounds' end codes, put into words. */
const VERDICT_REASONS = new Set(['uncited', 'number', 'name', 'unknown_id'])
const ROUND_REASONS = new Set([
  'write_now',
  'finish',
  'covered',
  'answered',
  'budget',
  'no_progress',
  'calls',
  'wall',
  'cost',
  'rounds',
  'gather',
  'nothing_queued',
])

/**
 * The sentence for a step. The title is the verb (`Searched`, `Read`,
 * `Wrote`), the detail the query, source or section it concerned — the
 * timeline quotes the detail beside the title, the live line reads both.
 */
export function researchStepCopy(
  t: Translate,
  step: ResearchStep,
  points: ResearchPoint[],
  sourceTitles: Record<string, string> = {}
): ResearchStepCopy {
  switch (step.kind) {
    case 'brief':
      return { title: t('research.step.brief'), detail: step.text, aside: '' }
    case 'plan':
      return {
        title: t('research.step.plan', { count: Number(step.text) || 0 }),
        detail: '',
        aside: '',
      }
    case 'thought':
      return { title: step.text, detail: '', aside: '' }
    case 'search':
      return {
        title: t(searchTitle(step.status)),
        detail: joinDetail(
          step.text,
          step.count === undefined ? '' : t('research.step.searchResults', { count: step.count })
        ),
        aside: '',
      }
    case 'source':
      return {
        title: t(`research.step.source.${sourceStatus(step.status)}`),
        detail: joinDetail(step.text, readCount(t, step)),
        aside: step.note ?? '',
      }
    case 'finding':
      return {
        title: t('research.step.finding'),
        detail: step.text,
        aside: findingAside(step, sourceTitles),
      }
    case 'point':
      return {
        title: t(`research.step.point.${pointStatus(step.status)}`),
        detail: step.text || (points.find((point) => point.id === step.ref)?.title ?? ''),
        aside: step.note ?? '',
      }
    case 'round':
      return {
        title: t(roundTitle(step.status), { round: step.text }),
        detail: '',
        aside: roundAside(t, step),
      }
    case 'writing':
      return { title: t('research.step.writing'), detail: step.text, aside: '' }
    case 'section':
      return { title: t('research.step.section'), detail: step.text, aside: '' }
    case 'checking':
      return { title: t('research.step.checking'), detail: '', aside: '' }
    case 'verdict':
      return {
        title: t(`research.step.verdict.${verdictStatus(step.status)}`),
        detail: step.text,
        aside: verdictAside(t, step),
      }
    case 'check':
      return {
        title: t('research.step.check', { ratio: step.text }),
        detail: checkDetail(t, step),
        aside: '',
      }
    case 'complete':
      return {
        title: t(
          step.text === 'answered_without_research'
            ? 'research.answeredWithout'
            : 'research.step.complete'
        ),
        detail: '',
        aside: '',
      }
    case 'stopped':
      return { title: t('research.step.stopped'), detail: '', aside: '' }
    case 'failed':
      return { title: t('research.step.failed'), detail: '', aside: '' }
    default:
      return { title: step.kind, detail: step.text, aside: '' }
  }
}

/** What the check left out of the answer, so the fold tells it even though the answer does not. */
function checkDetail(t: Translate, step: ResearchStep): string {
  const parts: string[] = []
  if ((step.check?.removed ?? 0) > 0) {
    parts.push(t('research.step.checkRemoved', { count: step.check?.removed ?? 0 }))
  }
  if (step.check?.sameProvider) parts.push(t('research.step.checkSameProvider'))
  return parts.join(' ')
}

/** A read source says what it gave: a count of findings, or that nothing in it served the plan. */
function readCount(t: Translate, step: ResearchStep): string {
  if (step.status !== 'read' || step.count === undefined) return ''
  if (step.count === 0) return t('research.step.sourceNothingNew')
  return t('research.step.sourceFindings', { count: step.count }, step.count)
}

/** The page's own words, and the source they come from. */
function findingAside(step: ResearchStep, sourceTitles: Record<string, string>): string {
  const quote = step.quote !== undefined && step.quote !== '' ? `“${step.quote}”` : ''
  const source = step.source !== undefined ? (sourceTitles[step.source] ?? '') : ''
  return [quote, source].filter((part) => part !== '').join(' — ')
}

/** Why the round ended: the run's code put into words, or the lead's own sentence. */
function roundAside(t: Translate, step: ResearchStep): string {
  if (step.note === undefined || step.note === '') return ''
  if (ROUND_REASONS.has(step.note)) return t(`research.step.roundReason.${step.note}`)
  return step.note
}

/** The checker's sentence, or the draft check's code put into words. */
function verdictAside(t: Translate, step: ResearchStep): string {
  if (step.note === undefined || step.note === '') return ''
  if (VERDICT_REASONS.has(step.note)) return t(`research.step.verdictReason.${step.note}`)
  return step.note
}

function joinDetail(first: string, second: string): string {
  return [first, second].filter((part) => part !== '').join(' · ')
}

function searchTitle(status: string | null): string {
  if (status === 'skipped') return 'research.step.searchSkipped'
  return status === 'files' ? 'research.step.searchFiles' : 'research.step.search'
}

/** What the run decided at the end of a round: look again, write, or wait for the person. */
function roundTitle(next: string | null): string {
  if (next === 'round' || next === 'continue') return 'research.step.roundAgain'
  if (next === 'write') return 'research.step.roundWrite'
  if (next === 'wait_answer') return 'research.step.roundWait'
  if (next === 'halt') return 'research.step.stopped'
  return 'research.step.round'
}

function sourceStatus(status: string | null): 'reading' | 'read' | 'blocked' | 'found' {
  return status === 'reading' || status === 'read' || status === 'blocked' ? status : 'found'
}

function pointStatus(status: string | null): 'answered' | 'not_confirmed' | 'open' {
  return status === 'answered' || status === 'not_confirmed' ? status : 'open'
}

function verdictStatus(
  status: string | null
): 'partial' | 'contradicted' | 'removed' | 'unsupported' {
  return status === 'partial' || status === 'contradicted' || status === 'removed'
    ? status
    : 'unsupported'
}
