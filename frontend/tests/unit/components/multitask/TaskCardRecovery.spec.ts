import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import TaskCard from '@/components/multitask/TaskCard.vue'
import type { TaskCard as TaskCardModel } from '@/stores/taskCard'

function card(overrides: Partial<TaskCardModel> = {}): TaskCardModel {
  return {
    nodeId: 'n1',
    capability: 'mcp_fetch',
    kind: 'search',
    state: 'done',
    ...overrides,
  }
}

function mountCard(model: TaskCardModel) {
  setActivePinia(createPinia())
  return mount(TaskCard, {
    props: { card: model },
    global: { stubs: { MessageText: true, TaskCardMedia: true } },
  })
}

describe('TaskCard search recovery', () => {
  it('names the title that matched after a miss', () => {
    const wrapper = mountCard(
      card({
        recovery: {
          attempts: [
            { query: 'Metadsit', hits: 0 },
            { query: 'Metadist', hits: 1 },
          ],
          matchedQuery: 'Metadist',
        },
      })
    )

    expect(wrapper.get('[data-testid="task-card-recovery"]').text()).toBe(
      'Nothing for “Metadsit” — searched “Metadist” instead.'
    )
  })

  it('says what was tried when nothing matched', () => {
    const wrapper = mountCard(
      card({
        recovery: {
          attempts: [
            { query: 'Zzzzz', hits: 0 },
            { query: 'Zzz', hits: 0 },
          ],
          empty: true,
        },
      })
    )

    expect(wrapper.get('[data-testid="task-card-recovery"]').text()).toBe(
      'Searched: Zzzzz, Zzz — nothing found.'
    )
  })

  it('says it is searching again while a wider query runs', () => {
    const wrapper = mountCard(card({ state: 'running', retrying: true }))

    expect(wrapper.get('[data-testid="task-card-searching-again"]').text()).toBe('Searching again…')
    expect(wrapper.find('[data-testid="task-card-recovery"]').exists()).toBe(false)
  })
})
