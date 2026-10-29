/**
 * The chat decided a question needs several sources and started a research
 * on its own. The stream says so once (`research_started`), before the one
 * sentence that becomes the fallback answer; the reply bubble then shows the
 * run working and, once it is done, its report. Signed-in turns only: a
 * guest has no chat for a report.
 */
import type { MemberTurn, StreamHandlers, StreamStatusHandler } from '../streamTurn'

const researchStarted: StreamStatusHandler<MemberTurn> = (data, turn) => {
  const runId = data.metadata?.run_id
  if (typeof runId !== 'string' || runId === '') return
  const message = turn.message()
  if (message) message.researchRunId = runId
  turn.research.adopt(runId)
}

export const autoResearchHandlers: StreamHandlers<MemberTurn> = {
  research_started: researchStarted,
}
