export const STEP_FIELD_CLASS =
  'mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed'

export const BUILTIN_STEP_KINDS = [
  'email_search',
  'summarize',
  'chat',
  'email_me',
  'condition',
  'outbound_webhook',
] as const

export type BuiltinStepKind = (typeof BUILTIN_STEP_KINDS)[number]

export type InputSpec = Record<string, unknown>

export type AuthoredStep = {
  id: string
  capability: string
  depends_on: string[]
  params: Record<string, unknown>
  /** Node-level inputs a planner-captured graph may carry (e.g. url_fetch). Kept verbatim. */
  inputs?: Record<string, unknown>
}

export type NewStepContext = {
  /** Id of the step directly before the new one, if any. */
  previousStepId?: string
  triggerType?: string
}

const isRecord = (value: unknown): value is Record<string, unknown> =>
  !!value && typeof value === 'object' && !Array.isArray(value)

export function emptyStep(
  capability: string,
  index: number,
  context: NewStepContext = {}
): AuthoredStep {
  const id = `step_${index + 1}`
  const params: Record<string, unknown> = {}
  if (capability === 'condition') {
    params.operator = 'not_empty'
    params.inputs = { input: defaultInputSource(context) }
  }
  if (capability === 'outbound_webhook') {
    params.url = ''
    params.inputs = { result: defaultInputSource(context) }
  }
  if (capability === 'tool_call') {
    params.tool = ''
    params.inputs = {}
  }
  return { id, capability, depends_on: [], params }
}

/**
 * A new condition reads the previous step when there is one; a webhook-started
 * task without earlier steps reads the whole starting event; otherwise a typed value.
 */
export function defaultInputSource(context: NewStepContext): InputSpec {
  if (context.previousStepId) return { from: context.previousStepId, field: 'text' }
  if (context.triggerType === 'webhook') return { from: 'trigger', field: '' }
  return { literal: '' }
}

/**
 * Argument names a tool accepts, read from its JSON Schema (`properties`),
 * required ones first. Empty when the tool publishes no schema.
 */
export function toolArgumentNames(inputSchema: unknown): { name: string; required: boolean }[] {
  if (!isRecord(inputSchema) || !isRecord(inputSchema.properties)) return []
  const required = new Set(
    Array.isArray(inputSchema.required)
      ? inputSchema.required.filter((item): item is string => typeof item === 'string')
      : []
  )
  return Object.keys(inputSchema.properties)
    .map((name) => ({ name, required: required.has(name) }))
    .sort((a, b) => Number(b.required) - Number(a.required))
}

export function stepsFromGraph(graph: Record<string, unknown> | null): AuthoredStep[] {
  const nodes = graph?.nodes
  if (!Array.isArray(nodes)) return []
  return nodes.flatMap((node, index) => {
    if (!isRecord(node)) return []
    const id = typeof node.id === 'string' && node.id ? node.id : `step_${index + 1}`
    const capability = typeof node.capability === 'string' ? node.capability : 'chat'
    const depends = Array.isArray(node.depends_on)
      ? node.depends_on.filter((item): item is string => typeof item === 'string')
      : []
    const params = isRecord(node.params) ? { ...node.params } : {}
    const step: AuthoredStep = { id, capability, depends_on: depends, params }
    if (isRecord(node.inputs)) step.inputs = { ...node.inputs }
    return [step]
  })
}

/**
 * Builds the graph the backend validates. Steps run in order (each depends on
 * the previous one) plus on any step they read a value from. Graph-level keys
 * the planner captured (`prompt_topic`, `settings`, …) are carried over.
 */
export function graphFromSteps(
  steps: AuthoredStep[],
  triggerType: string,
  existing: Record<string, unknown> | null = null
): Record<string, unknown> {
  const ids = new Set(steps.map((step) => step.id))
  const nodes = steps.map((step, index) => {
    const fromIds = inputFromIds(step).filter((id) => ids.has(id))
    const linear = index === 0 ? [] : [steps[index - 1].id]
    const depends_on = [...new Set([...linear, ...fromIds])]
    return { ...step, depends_on }
  })
  const carried: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(existing ?? {})) {
    if (key === 'version' || key === 'trigger' || key === 'nodes') continue
    if (key === 'reply_node' && !(typeof value === 'string' && ids.has(value))) continue
    carried[key] = value
  }
  return {
    ...carried,
    version: 1,
    trigger: { type: triggerType },
    nodes,
  }
}

/**
 * Re-ids steps as `step_1 … step_n` in their current order and rewrites every
 * `from:` reference to follow the step it pointed at. A reference to a removed
 * step, or to a step that now comes later, falls back to a typed value so the
 * graph stays valid.
 */
export function renumberSteps(steps: AuthoredStep[]): AuthoredStep[] {
  const newIdByOld = new Map(steps.map((step, index) => [step.id, `step_${index + 1}`]))
  const position = new Map(steps.map((step, index) => [step.id, index]))
  return steps.map((step, index) => {
    const remap = (inputs: unknown): Record<string, unknown> | undefined => {
      if (!isRecord(inputs)) return undefined
      const out: Record<string, unknown> = {}
      for (const [key, spec] of Object.entries(inputs)) {
        if (!isRecord(spec) || typeof spec.from !== 'string' || spec.from === 'trigger') {
          out[key] = spec
          continue
        }
        const target = position.get(spec.from)
        if (target === undefined || target >= index) {
          out[key] = { literal: '' }
          continue
        }
        out[key] = { ...spec, from: newIdByOld.get(spec.from) }
      }
      return out
    }
    const next: AuthoredStep = {
      ...step,
      id: `step_${index + 1}`,
      params: { ...step.params },
    }
    const paramInputs = remap(step.params.inputs)
    if (paramInputs) next.params.inputs = paramInputs
    const nodeInputs = remap(step.inputs)
    if (nodeInputs) next.inputs = nodeInputs
    return next
  })
}

function inputFromIds(step: AuthoredStep): string[] {
  const sources = [step.params.inputs, step.inputs]
  return sources.flatMap((inputs) => {
    if (!isRecord(inputs)) return []
    return Object.values(inputs).flatMap((spec) => {
      if (!isRecord(spec)) return []
      const from = spec.from
      return typeof from === 'string' && from && from !== 'trigger' ? [from] : []
    })
  })
}
