import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ComputeRunCard from '@/components/multitask/ComputeRunCard.vue'
import type { TaskCard } from '@/stores/history'
import en from '@/i18n/en.json'

function mountCard(card: Partial<TaskCard>) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: { en },
  })
  return mount(ComputeRunCard, {
    props: {
      card: {
        nodeId: 'n1',
        capability: 'code_run',
        kind: 'compute',
        state: 'failed',
        ...card,
      },
    },
    global: { plugins: [i18n] },
  })
}

describe('ComputeRunCard', () => {
  it('shows the quota sentence, not a raw error code', () => {
    const wrapper = mountCard({
      state: 'failed',
      error: "You have used this week's file-work limit. Nothing new was saved.",
    })

    expect(wrapper.get('[data-testid="compute-run-error"]').text()).toContain(
      "You have used this week's file-work limit"
    )
    expect(wrapper.text()).not.toContain('sandbox')
    expect(wrapper.text()).not.toContain('stdout')
  })
})
