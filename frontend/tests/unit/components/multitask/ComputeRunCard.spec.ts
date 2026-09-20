import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ComputeRunCard from '@/components/multitask/ComputeRunCard.vue'
import type { TaskCard } from '@/stores/history'
import { asI18nSchema, loadAllMessages } from '@/i18n/loadAllMessages'

const en = loadAllMessages('en')

const runtimeFeatures = { computeWorkspacesEnabled: false }

vi.mock('@/services/api/httpClient', () => ({
  getConfigSync: () => ({ features: runtimeFeatures }),
}))

function mountCard(card: Partial<TaskCard>) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: { en: asI18nSchema(en) },
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
    global: {
      plugins: [i18n],
      stubs: { RouterLink: { template: '<a><slot /></a>', props: ['to'] } },
    },
  })
}

describe('ComputeRunCard', () => {
  beforeEach(() => {
    runtimeFeatures.computeWorkspacesEnabled = false
  })

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
      messages: { en: asI18nSchema(en) },
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
      global: {
        plugins: [i18n],
        stubs: { RouterLink: { template: '<a><slot /></a>', props: ['to'] } },
      },
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

  it('hides Open workspace when the folder flag is off', () => {
    runtimeFeatures.computeWorkspacesEnabled = false
    const wrapper = mountCard({ state: 'done', url: '/files/1' })
    expect(wrapper.find('[data-testid="compute-run-workspace"]').exists()).toBe(false)
  })

  it('shows Open workspace only when this run used the folder', () => {
    runtimeFeatures.computeWorkspacesEnabled = true
    expect(
      mountCard({ state: 'done' }).find('[data-testid="compute-run-workspace"]').exists()
    ).toBe(false)
    const used = mountCard({ state: 'done', usedWorkspace: true })
    expect(used.get('[data-testid="compute-run-workspace"]').text()).toContain('Open workspace')
    expect(
      mountCard({ state: 'failed', usedWorkspace: true })
        .get('[data-testid="compute-run-workspace"]')
        .text()
    ).toContain('Open workspace')
  })
})
