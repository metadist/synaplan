import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import StepInputsForm from '@/components/config/workflows/StepInputsForm.vue'
import type { AuthoredStep } from '@/components/config/workflows/stepTypes'

const conditionStep = (operator = 'equals'): AuthoredStep => ({
  id: 'step_2',
  capability: 'condition',
  depends_on: ['step_1'],
  params: { operator, value: 'yes', inputs: { input: { literal: '' } } },
})

const earlier: AuthoredStep[] = [{ id: 'step_1', capability: 'chat', depends_on: [], params: {} }]

describe('StepInputsForm', () => {
  it('lets a condition pick a typed value or an earlier step', async () => {
    const wrapper = mount(StepInputsForm, {
      props: { step: conditionStep(), earlierSteps: earlier, tools: [] },
    })
    expect(wrapper.text()).toContain('Only continue if')
    expect(wrapper.text()).toContain('From step 1')
    const selects = wrapper.findAll('select')
    await selects[1].setValue('step_1')
    const change = wrapper.emitted('change')?.[0]?.[0] as AuthoredStep
    expect(change.params.inputs).toEqual({ input: { from: 'step_1', field: 'text' } })
  })

  it('shows the compare field for equals, contains and matches — not for not_empty', () => {
    for (const operator of ['equals', 'contains', 'matches']) {
      const wrapper = mount(StepInputsForm, {
        props: { step: conditionStep(operator), earlierSteps: earlier, tools: [] },
      })
      expect(wrapper.find('[data-testid="condition-value"]').exists(), operator).toBe(true)
    }
    const wrapper = mount(StepInputsForm, {
      props: { step: conditionStep('not_empty'), earlierSteps: earlier, tools: [] },
    })
    expect(wrapper.find('[data-testid="condition-value"]').exists()).toBe(false)
  })

  it('maps tool arguments from the tool schema, required first', async () => {
    const wrapper = mount(StepInputsForm, {
      props: {
        step: {
          id: 'step_2',
          capability: 'tool_call',
          depends_on: ['step_1'],
          params: { tool: 'crm.create', inputs: {} },
        },
        earlierSteps: earlier,
        tools: [
          {
            name: 'crm.create',
            title: 'Create contact',
            description: '',
            sideEffect: 'write',
            source: 'custom',
            inputSchema: {
              type: 'object',
              properties: { note: { type: 'string' }, name: { type: 'string' } },
              required: ['name'],
            },
          },
        ],
      },
    })
    const labels = wrapper.findAll('[data-testid="tool-arguments"] label').map((l) => l.text())
    expect(labels[0]).toContain('name')
    expect(labels[0]).toContain('required')
    expect(labels[1]).toContain('note')

    await wrapper.find('[data-testid="input-source-name"] select').setValue('trigger')
    const change = wrapper.emitted('change')?.[0]?.[0] as AuthoredStep
    expect(change.params.inputs).toEqual({ name: { from: 'trigger', field: '' } })
  })

  it('starts the argument mapping fresh when the tool changes', async () => {
    const wrapper = mount(StepInputsForm, {
      props: {
        step: {
          id: 'step_1',
          capability: 'tool_call',
          depends_on: [],
          params: { tool: 'a', inputs: { x: { literal: '1' } } },
        },
        earlierSteps: [],
        tools: [
          { name: 'a', title: 'A', description: '', sideEffect: 'read', source: 'custom' },
          { name: 'b', title: 'B', description: '', sideEffect: 'read', source: 'custom' },
        ],
      },
    })
    await wrapper.find('select').setValue('b')
    const change = wrapper.emitted('change')?.[0]?.[0] as AuthoredStep
    expect(change.params).toEqual({ tool: 'b', inputs: {} })
  })

  it('keeps a saved outbound secret untouched until the owner types or clears it', async () => {
    const step: AuthoredStep = {
      id: 'step_2',
      capability: 'outbound_webhook',
      depends_on: ['step_1'],
      params: { url: 'https://hooks.example/in', secretConfigured: true },
    }
    const wrapper = mount(StepInputsForm, {
      props: { step, earlierSteps: earlier, tools: [] },
    })
    const secret = wrapper.find('[data-testid="outbound-secret"]')
    expect(secret.attributes('type')).toBe('password')
    expect(secret.attributes('placeholder')).toBe('A secret is saved')
    expect(wrapper.text()).toContain('What to send')

    await secret.setValue('new-secret')
    const change = wrapper.emitted('change')?.[0]?.[0] as AuthoredStep
    expect(change.params).toEqual({ url: 'https://hooks.example/in', secret: 'new-secret' })
  })

  it('says so when the chosen tool takes no input', () => {
    const wrapper = mount(StepInputsForm, {
      props: {
        step: { id: 'step_1', capability: 'tool_call', depends_on: [], params: { tool: 'a' } },
        earlierSteps: [],
        tools: [{ name: 'a', title: 'A', description: '', sideEffect: 'read', source: 'custom' }],
      },
    })
    expect(wrapper.text()).toContain('This tool takes no input.')
    expect(wrapper.find('[data-testid="tool-arguments"]').exists()).toBe(false)
  })
})
