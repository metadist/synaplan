import type { TaskPlanState } from '@/stores/history'

/**
 * Multitask routing, #1229 smart collapse: mark a task card's prose as
 * REDUNDANT when that text is already contained in the final answer shown in
 * the message body.
 *
 * A DAG whose reply node passes an upstream text node through verbatim renders
 * the same prose twice — once streamed into the step card (`task_chunk`) and
 * again as the assembled `compose_reply` answer in the message body (#1057).
 * The body is the canonical answer surface (it owns copy / feedback / voice),
 * so the duplicated card COLLAPSES to its header instead of repeating the wall
 * of text — while a card with unique content (the reply is just a short
 * connector sentence) stays fully visible.
 *
 * The backend sets the same flag at assembly time (`ResultAssembler`,
 * persisted in `task_plan_render`); this client-side marker covers the LIVE
 * streamed cards of the current turn and messages persisted before the flag
 * existed. Containment — not equality — mirrors the backend semantics.
 *
 * Returns the same reference when nothing changed so Vue's computed stays cheap.
 */
export function markRedundantTaskPlanProse(
  plan: TaskPlanState | null | undefined,
  bodyTexts: readonly string[]
): TaskPlanState | null {
  if (!plan || plan.cards.length === 0) {
    return plan ?? null
  }

  const normalize = (s: string) => s.trim().replace(/\s+/g, ' ')
  const body = normalize(bodyTexts.join('\n'))
  if (body.length === 0) {
    return plan
  }

  let changed = false
  const cards = plan.cards.map((card) => {
    if (!card.redundant && card.text && body.includes(normalize(card.text))) {
      changed = true
      return { ...card, redundant: true }
    }
    return card
  })

  return changed ? { ...plan, cards } : plan
}
