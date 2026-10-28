/**
 * The chat decided a question needs several sources and started a research
 * on its own. The stream says so once (`research_started`), before the one
 * sentence that becomes the answer; the card below the thread then follows
 * the run. Signed-in turns only: a guest has no chat for a report.
 */
import type { MemberTurn, StreamHandlers, StreamStatusHandler } from '../streamTurn'

const researchStarted: StreamStatusHandler<MemberTurn> = (data, turn) => {
  const runId = data.metadata?.run_id
  if (typeof runId !== 'string' || runId === '') return
  turn.research.adopt(runId)
}

export const autoResearchHandlers: StreamHandlers<MemberTurn> = {
  research_started: researchStarted,
}
