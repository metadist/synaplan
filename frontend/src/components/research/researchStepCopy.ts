import type { ResearchPoint, ResearchStep } from '@/services/chat/stream/handlers/research'

type Translate = (key: string, params?: Record<string, unknown>) => string

/** One step as a row: what was done, and the words it concerned. */
export interface ResearchStepCopy {
  title: string
  detail: string
}

/**
 * The sentence for a step. The title is the verb (`Searched`, `Read`,
 * `Wrote`), the detail the query, source or section it concerned — the
 * timeline quotes the detail beside the title, the live line reads both.
 */
export function researchStepCopy(
  t: Translate,
  step: ResearchStep,
  points: ResearchPoint[]
): ResearchStepCopy {
  switch (step.kind) {
    case 'brief':
      return { title: t('research.step.brief'), detail: step.text }
    case 'plan':
      return { title: t('research.step.plan', { count: Number(step.text) || 0 }), detail: '' }
    case 'search':
      return {
        title: t(step.status === 'files' ? 'research.step.searchFiles' : 'research.step.search'),
        detail: step.text,
      }
    case 'source':
      return { title: t(`research.step.source.${sourceStatus(step.status)}`), detail: step.text }
    case 'point':
      return {
        title: t(`research.step.point.${pointStatus(step.status)}`),
        detail: step.text || (points.find((point) => point.id === step.ref)?.title ?? ''),
      }
    case 'round':
      return { title: t('research.step.round', { round: step.text }), detail: '' }
    case 'writing':
      return { title: t('research.step.writing'), detail: step.text }
    case 'section':
      return { title: t('research.step.section'), detail: step.text }
    case 'checking':
      return { title: t('research.step.checking'), detail: '' }
    case 'check':
      return { title: t('research.step.check', { ratio: step.text }), detail: '' }
    case 'complete':
      return {
        title: t(
          step.text === 'answered_without_research'
            ? 'research.answeredWithout'
            : 'research.step.complete'
        ),
        detail: '',
      }
    case 'stopped':
      return { title: t('research.step.stopped'), detail: '' }
    case 'failed':
      return { title: t('research.step.failed'), detail: '' }
    default:
      return { title: step.kind, detail: step.text }
  }
}

function sourceStatus(status: string | null): 'read' | 'blocked' | 'found' {
  return status === 'read' || status === 'blocked' ? status : 'found'
}

function pointStatus(status: string | null): 'answered' | 'not_confirmed' | 'open' {
  return status === 'answered' || status === 'not_confirmed' ? status : 'open'
}
