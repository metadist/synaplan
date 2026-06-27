import type { TaskPlanState } from '@/stores/history'

/**
 * Multitask routing: drop the duplicate prose from a task card when that exact
 * text is already shown as the final answer in the message body.
 *
 * A DAG whose reply node passes an upstream text node through verbatim renders
 * the same prose twice — once streamed into the step card (`task_chunk`) and
 * again as the assembled `compose_reply` answer in the message body (#1057).
 * This is not specific to "poem → TTS": it hits every text-result plan where
 * the reply equals an upstream node's output (summarize → translate, extract →
 * summarize, web_search → chat, …).
 *
 * Media never duplicated (URL dedup in the mapper) and search cards were
 * already compacted (#1076); this brings prose cards in line: the body stays
 * the single canonical answer surface (it owns the copy / feedback / voice
 * controls) and the duplicated step card collapses to its "Done" status.
 *
 * Returns the same reference when nothing changed so Vue's computed stays cheap.
 */
export function dedupeTaskPlanProse(
  plan: TaskPlanState | null | undefined,
  bodyTexts: readonly string[]
): TaskPlanState | null {
  if (!plan || plan.cards.length === 0) {
    return plan ?? null
  }

  const normalize = (s: string) => s.trim()
  const body = new Set(bodyTexts.map(normalize).filter((t) => t.length > 0))
  if (body.size === 0) {
    return plan
  }

  let changed = false
  const cards = plan.cards.map((card) => {
    if (card.text && body.has(normalize(card.text))) {
      changed = true
      return { ...card, text: '' }
    }
    return card
  })

  return changed ? { ...plan, cards } : plan
}
