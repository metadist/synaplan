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

  it('hides rerun on a read-only card', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: { en },
    })
    const wrapper = mount(ComputeRunCard, {
      props: {
        card: {
          nodeId: 'n1',
          capability: 'code_run',
          kind: 'compute',
          state: 'failed',
          error: 'File work could not finish. Nothing new was saved.',
        },
        isReadonly: true,
      },
      global: { plugins: [i18n] },
    })

    expect(wrapper.find('[data-testid="compute-run-rerun"]').exists()).toBe(false)
  })

  it('labels the notes field and disables submit until notes are entered', async () => {
    const wrapper = mountCard({
      state: 'failed',
      error: 'File work could not finish. Nothing new was saved.',
    })

    await wrapper.get('[data-testid="compute-run-rerun"]').trigger('click')

    const notes = wrapper.get('[data-testid="compute-run-notes"]')
    expect(notes.attributes('id')).toBe('compute-run-notes-n1')
    expect(wrapper.get('label[for="compute-run-notes-n1"]').text()).toContain(
      'Notes for the next attempt'
    )
    expect(
      wrapper.get('[data-testid="compute-run-rerun-submit"]').attributes('disabled')
    ).toBeDefined()

    await notes.setValue('try with the spreadsheet')
    expect(
      wrapper.get('[data-testid="compute-run-rerun-submit"]').attributes('disabled')
    ).toBeUndefined()
  })
})
