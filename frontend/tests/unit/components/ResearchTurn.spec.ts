import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createI18n } from 'vue-i18n'
import ResearchTurn from '@/components/research/ResearchTurn.vue'
import { emptyProgress, type ResearchProgress } from '@/services/chat/stream/handlers/research'
import { useResearchStore } from '@/stores/research'

vi.mock('@/services/api/researchApi', () => ({
  fetchResearchEvents: vi.fn(async () => ''),
}))

const messages = {
  en: {
    research: {
      turn: {
        working: 'Researching…',
        stepsSoFar: '{steps} steps so far · {sources} sources read',
        summary: {
          complete: 'Researched: {steps} steps · {sources} sources read',
          stopped: 'Research stopped after {steps} steps',
          failed: 'Research could not finish',
        },
      },
      answeredWithout: 'Answered without the extra look.',
      queued: 'Research is queued.',
      answerNow: 'Answer now',
      answerNowConsequence: { writing: 'writing', reading: 'reading', early: 'early' },
    },
  },
}

function mountTurn(status: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const research = useResearchStore()
  const progress: ResearchProgress = {
    ...emptyProgress('run-1', 1, 'What does one unit cost?'),
    status,
    steps: [{ kind: 'search', text: 'unit price', status: null, ref: null }],
  }
  research.progress = progress

  return mount(ResearchTurn, {
    props: { runId: 'run-1' },
    global: { plugins: [pinia, createI18n({ legacy: false, locale: 'en', messages })] },
  })
}

/** The first icon of the summary button: the brand check, or the warning triangle. */
function summaryIconClasses(status: string): string {
  const button = mountTurn(status).find('[data-testid="btn-research-steps"]')

  return button.find('svg').attributes('class') ?? ''
}

describe('ResearchTurn', () => {
  it('marks a stopped run with a warning, not a success check', () => {
    const wrapper = mountTurn('stopped')

    expect(summaryIconClasses('stopped')).toContain('text-red-600')
    expect(wrapper.find('[data-testid="text-research-summary"]').text()).toBe(
      'Research stopped after 1 steps'
    )
  })

  it('marks a failed run with a warning, not a success check', () => {
    const wrapper = mountTurn('failed')

    expect(summaryIconClasses('failed')).toContain('text-red-600')
    expect(wrapper.find('[data-testid="text-research-summary"]').text()).toBe(
      'Research could not finish'
    )
  })

  it('keeps the check on a completed run', () => {
    const wrapper = mountTurn('complete')

    expect(summaryIconClasses('complete')).toContain('txt-brand')
    expect(summaryIconClasses('complete')).not.toContain('text-red-600')
    expect(wrapper.find('[data-testid="text-research-summary"]').text()).toBe(
      'Researched: 1 steps · 0 sources read'
    )
  })
})
