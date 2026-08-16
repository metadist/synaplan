import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ExecutedPlanGraph from '@/components/multitask/ExecutedPlanGraph.vue'
import type { TaskPlanState } from '@/stores/history'

function plan(cards: TaskPlanState['cards']): TaskPlanState {
  return { active: false, replyNode: 'n2', cards }
}

describe('ExecutedPlanGraph', () => {
  it('renders steps and an edge from a multi-step plan', () => {
    const wrapper = mount(ExecutedPlanGraph, {
      props: {
        plan: plan([
          {
            nodeId: 'n1',
            capability: 'email_search',
            kind: 'email',
            state: 'done',
            dependsOn: [],
          },
          {
            nodeId: 'n2',
            capability: 'chat',
            kind: 'text',
            state: 'done',
            dependsOn: ['n1'],
          },
        ]),
      },
    })

    expect(wrapper.find('[data-testid="executed-plan-graph"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="plan-step-n1"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="plan-step-n2"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('n1')
    expect(wrapper.text()).toContain('→')
  })

  it('renders nothing for a single-step plan', () => {
    const wrapper = mount(ExecutedPlanGraph, {
      props: {
        plan: plan([
          {
            nodeId: 'n1',
            capability: 'chat',
            kind: 'text',
            state: 'done',
            dependsOn: [],
          },
        ]),
      },
    })

    expect(wrapper.find('[data-testid="executed-plan-graph"]').exists()).toBe(false)
  })

  it('renders nothing when the plan has no cards', () => {
    const wrapper = mount(ExecutedPlanGraph, {
      props: { plan: plan([]) },
    })

    expect(wrapper.find('[data-testid="executed-plan-graph"]').exists()).toBe(false)
  })
})
