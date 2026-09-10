import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StepInputsForm from '@/components/config/workflows/StepInputsForm.vue'
import type { AuthoredStep } from '@/components/config/workflows/stepTypes'

const conditionStep = (): AuthoredStep => ({
  id: 'step_2',
  capability: 'condition',
  depends_on: ['step_1'],
  params: { operator: 'equals', value: 'yes', inputs: { input: { literal: '' } } },
})

describe('StepInputsForm', () => {
  it('lets a condition pick a typed value or an earlier step', async () => {
    const wrapper = mount(StepInputsForm, {
      props: {
        step: conditionStep(),
        earlierSteps: [{ id: 'step_1', capability: 'chat', depends_on: [], params: {} }],
        tools: [],
      },
    })
    expect(wrapper.text()).toContain('Only continue if')
    expect(wrapper.text()).toContain('From step 1')
    const selects = wrapper.findAll('select')
    await selects[1].setValue('step_1')
    const change = wrapper.emitted('change')?.[0]?.[0] as AuthoredStep
    expect(change.params.inputs).toEqual({ input: { from: 'step_1', field: 'text' } })
  })
})
