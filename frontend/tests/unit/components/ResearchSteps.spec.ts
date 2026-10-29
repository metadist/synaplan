import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ResearchSteps from '@/components/research/ResearchSteps.vue'
import type { ResearchStep } from '@/services/chat/stream/handlers/research'

const messages = {
  en: { research: { step: { finding: 'Found', source: { read: 'Read' } } } },
}

const STEPS: ResearchStep[] = [
  {
    kind: 'finding',
    text: 'The fee is 30 EUR for every adult who applies at the embassy.',
    status: 'high',
    ref: 'p1',
    quote: 'Fee: 30 EUR',
    source: 's1',
  },
  {
    kind: 'thought',
    text: 'The embassy has the fee. I check the time next.',
    status: null,
    ref: null,
  },
]

function mountSteps(live: boolean) {
  return mount(ResearchSteps, {
    props: { steps: STEPS, points: [], sourceTitles: { s1: 'Embassy' }, live },
    global: { plugins: [createI18n({ legacy: false, locale: 'en', messages })] },
  })
}

describe('ResearchSteps', () => {
  it('keeps the row happening now open and folds the done ones to one line', () => {
    const wrapper = mountSteps(true)
    const [done, current] = wrapper.findAll('li')

    expect(done.attributes('data-expanded')).toBe('false')
    expect(done.find('[data-testid="text-research-step-aside"]').exists()).toBe(false)
    expect(current.attributes('data-expanded')).toBe('true')
    expect(current.attributes('data-testid')).toBe('text-research-activity')
  })

  it('opens a folded row on a click to show what the page said', async () => {
    const wrapper = mountSteps(true)
    const done = wrapper.findAll('li')[0]

    await done.find('[data-testid="btn-research-step"]').trigger('click')

    expect(done.attributes('data-expanded')).toBe('true')
    expect(done.find('[data-testid="text-research-step-aside"]').text()).toBe(
      '“Fee: 30 EUR” — Embassy'
    )
  })

  it('folds every row once the phase is done', () => {
    const wrapper = mountSteps(false)

    expect(wrapper.findAll('li').map((row) => row.attributes('data-expanded'))).toEqual([
      'false',
      'false',
    ])
  })
})
