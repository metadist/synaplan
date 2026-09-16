import { describe, expect, it } from 'vitest'
import {
  defaultInputSource,
  emptyStep,
  graphFromSteps,
  renumberSteps,
  stepsFromGraph,
  toolArgumentNames,
  type AuthoredStep,
} from '@/components/config/workflows/stepTypes'

const step = (
  id: string,
  capability: string,
  params: Record<string, unknown> = {}
): AuthoredStep => ({
  id,
  capability,
  depends_on: [],
  params,
})

describe('stepTypes', () => {
  it('keeps planner-captured node inputs and graph-level keys when saving', () => {
    const existing = {
      version: 1,
      trigger: { type: 'manual' },
      prompt_topic: 'tools:inbox',
      reply_node: 'step_2',
      settings: { language: 'en' },
      nodes: [
        {
          id: 'step_1',
          capability: 'url_fetch',
          inputs: { url: { literal: 'https://a.example' } },
        },
        { id: 'step_2', capability: 'summarize', params: {} },
      ],
    }
    const steps = stepsFromGraph(existing)
    expect(steps[0].inputs).toEqual({ url: { literal: 'https://a.example' } })

    const graph = graphFromSteps(steps, 'schedule', existing)
    expect(graph.prompt_topic).toBe('tools:inbox')
    expect(graph.settings).toEqual({ language: 'en' })
    expect(graph.reply_node).toBe('step_2')
    expect(graph.trigger).toEqual({ type: 'schedule' })
    const nodes = graph.nodes as Array<Record<string, unknown>>
    expect(nodes[0].inputs).toEqual({ url: { literal: 'https://a.example' } })
    expect(nodes[1].depends_on).toEqual(['step_1'])
  })

  it('drops a reply_node that no longer points at a step', () => {
    const graph = graphFromSteps([step('step_1', 'chat')], 'manual', {
      reply_node: 'step_9',
    })
    expect(graph).not.toHaveProperty('reply_node')
  })

  it('follows from: references when steps move', () => {
    const steps = [
      step('step_1', 'chat'),
      step('step_2', 'email_search'),
      step('step_3', 'condition', {
        operator: 'not_empty',
        inputs: { input: { from: 'step_2', field: 'text' } },
      }),
    ]
    // Move the search first: step_2 → step_1; the condition must follow it.
    const moved = renumberSteps([steps[1], steps[0], steps[2]])
    expect(moved.map((row) => row.id)).toEqual(['step_1', 'step_2', 'step_3'])
    expect(moved[0].capability).toBe('email_search')
    expect(moved[2].params.inputs).toEqual({ input: { from: 'step_1', field: 'text' } })
  })

  it('falls back to a typed value when the referenced step is removed or comes later', () => {
    const condition = step('step_2', 'condition', {
      inputs: { input: { from: 'step_1', field: 'text' } },
    })
    const removed = renumberSteps([condition])
    expect(removed[0].params.inputs).toEqual({ input: { literal: '' } })

    const later = renumberSteps([condition, step('step_1', 'chat')])
    expect(later[0].params.inputs).toEqual({ input: { literal: '' } })
    expect(later[1].id).toBe('step_2')
  })

  it('leaves trigger and literal inputs untouched when renumbering', () => {
    const [row] = renumberSteps([
      step('step_7', 'tool_call', {
        tool: 'crm.create',
        inputs: { name: { literal: 'Ada' }, title: { from: 'trigger', field: 'title' } },
      }),
    ])
    expect(row.id).toBe('step_1')
    expect(row.params.inputs).toEqual({
      name: { literal: 'Ada' },
      title: { from: 'trigger', field: 'title' },
    })
  })

  it('reads the previous step by default, else the whole starting event of a webhook task', () => {
    expect(defaultInputSource({ previousStepId: 'step_1' })).toEqual({
      from: 'step_1',
      field: 'text',
    })
    expect(defaultInputSource({ triggerType: 'webhook' })).toEqual({ from: 'trigger', field: '' })
    expect(defaultInputSource({ triggerType: 'manual' })).toEqual({ literal: '' })
    expect(emptyStep('condition', 0, { triggerType: 'webhook' }).params.inputs).toEqual({
      input: { from: 'trigger', field: '' },
    })
    expect(emptyStep('outbound_webhook', 1, { previousStepId: 'step_1' }).params.inputs).toEqual({
      result: { from: 'step_1', field: 'text' },
    })
  })

  it('lists tool arguments from the JSON Schema, required first', () => {
    expect(
      toolArgumentNames({
        type: 'object',
        properties: { note: { type: 'string' }, id: { type: 'integer' } },
        required: ['id'],
      })
    ).toEqual([
      { name: 'id', required: true },
      { name: 'note', required: false },
    ])
    expect(toolArgumentNames(undefined)).toEqual([])
    expect(toolArgumentNames({ type: 'object' })).toEqual([])
  })
})
