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

export type AuthoredStep = {
  id: string
  capability: string
  depends_on: string[]
  params: Record<string, unknown>
}

export function emptyStep(capability: string, index: number): AuthoredStep {
  const id = `step_${index + 1}`
  const params: Record<string, unknown> = {}
  if (capability === 'condition') {
    params.operator = 'not_empty'
    params.inputs = { input: { from: 'trigger', field: 'body' } }
  }
  if (capability === 'outbound_webhook') {
    params.url = ''
  }
  if (capability === 'tool_call') {
    params.tool = ''
    params.inputs = {}
  }
  return { id, capability, depends_on: [], params }
}

export function stepsFromGraph(graph: Record<string, unknown> | null): AuthoredStep[] {
  const nodes = graph?.nodes
  if (!Array.isArray(nodes)) return []
  return nodes.flatMap((node, index) => {
    if (!node || typeof node !== 'object') return []
    const row = node as Record<string, unknown>
    const id = typeof row.id === 'string' && row.id ? row.id : `step_${index + 1}`
    const capability = typeof row.capability === 'string' ? row.capability : 'chat'
    const depends = Array.isArray(row.depends_on)
      ? row.depends_on.filter((item): item is string => typeof item === 'string')
      : []
    const params =
      row.params && typeof row.params === 'object' && !Array.isArray(row.params)
        ? { ...(row.params as Record<string, unknown>) }
        : {}
    return [{ id, capability, depends_on: depends, params }]
  })
}

export function graphFromSteps(
  steps: AuthoredStep[],
  triggerType: string
): Record<string, unknown> {
  const nodes = steps.map((step, index) => {
    const fromIds = inputFromIds(step)
    const linear = index === 0 ? [] : [steps[index - 1].id]
    const depends_on = [...new Set([...linear, ...fromIds])]
    return { ...step, depends_on }
  })
  return {
    version: 1,
    trigger: { type: triggerType },
    nodes,
  }
}

function inputFromIds(step: AuthoredStep): string[] {
  const inputs = step.params.inputs
  if (!inputs || typeof inputs !== 'object' || Array.isArray(inputs)) return []
  return Object.values(inputs as Record<string, unknown>).flatMap((spec) => {
    if (!spec || typeof spec !== 'object' || Array.isArray(spec)) return []
    const from = (spec as { from?: unknown }).from
    return typeof from === 'string' && from && from !== 'trigger' ? [from] : []
  })
}
