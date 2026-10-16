/**
 * Turns chat stream events into updates of the answer, through one dispatch
 * table per audience built from the same handler modules. A status only one
 * audience handles is registered only for it, so a guest stream can never
 * reach memory code. Every event of an aborted turn is dropped.
 */
import type { StreamUpdatePayload } from '@/types/chatStream'
import { guestCompletionHandlers, memberCompletionHandlers } from './handlers/completion'
import { contentHandlers, memberContentHandlers } from './handlers/content'
import { continuationHandlers } from './handlers/continuation'
import { guestHandlers } from './handlers/guest'
import { memoryHandlers } from './handlers/memory'
import {
  guestProgressHandlers,
  memberProgressHandlers,
  progressHandlers,
} from './handlers/progress'
import { taskHandlers } from './handlers/tasks'
import type { ContinueTurn, GuestTurn, MemberTurn, StreamHandlers, StreamTurn } from './streamTurn'

export type StreamUpdateListener = (data: StreamUpdatePayload) => void

const sharedHandlers: StreamHandlers<StreamTurn> = {
  ...progressHandlers,
  ...taskHandlers,
  ...contentHandlers,
}

const memberHandlers: StreamHandlers<MemberTurn> = {
  ...sharedHandlers,
  ...memberProgressHandlers,
  ...memberContentHandlers,
  ...memoryHandlers,
  ...memberCompletionHandlers,
}

const guestTurnHandlers: StreamHandlers<GuestTurn> = {
  ...sharedHandlers,
  ...guestProgressHandlers,
  ...guestHandlers,
  ...guestCompletionHandlers,
}

export function createMemberStreamHandler(turn: MemberTurn): StreamUpdateListener {
  return dispatcher(turn, memberHandlers, {
    observe: traceTaskEvent,
    unknown: (data) => console.warn('⚠️ Unknown status:', data.status, data),
  })
}

export function createGuestStreamHandler(turn: GuestTurn): StreamUpdateListener {
  return dispatcher(turn, guestTurnHandlers, {})
}

export function createContinueStreamHandler(turn: ContinueTurn): StreamUpdateListener {
  return (data) => {
    const handler = data.status === undefined ? undefined : continuationHandlers[data.status]
    handler?.(data, turn)
  }
}

interface DispatchHooks {
  observe?: StreamUpdateListener
  unknown?: StreamUpdateListener
}

function dispatcher<T extends StreamTurn>(
  turn: T,
  handlers: StreamHandlers<T>,
  hooks: DispatchHooks
): StreamUpdateListener {
  return (data) => {
    if (turn.isAborted()) return
    turn.run.ingestTimeline(data)
    if (data.status === 'complete' || data.status === 'error') {
      turn.run.noteTerminal(data)
    }
    hooks.observe?.(data)

    const handler = data.status === undefined ? undefined : handlers[data.status]
    if (handler) {
      handler(data, turn)
    } else {
      hooks.unknown?.(data)
    }
  }
}

/**
 * A task card stuck at "Rendering… 95%" gives no clue whether the closing
 * events ever arrive. Logs the plan lifecycle after
 * `localStorage.setItem('synaplanDebug', '1')`, so production consoles stay
 * quiet and no file URL or error leaks unless a developer opts in.
 */
function traceTaskEvent(data: StreamUpdatePayload): void {
  try {
    const status = data.status
    if (
      typeof window === 'undefined' ||
      !window.localStorage?.getItem('synaplanDebug') ||
      typeof status !== 'string' ||
      !(status === 'plan' || status === 'plan_discarded' || status.startsWith('task_'))
    ) {
      return
    }
    console.debug('[i2v-debug]', status, {
      node_id: data.metadata?.node_id,
      state: data.metadata?.state,
      percent: data.metadata?.percent,
      provider_status: data.metadata?.provider_status,
      elapsed_seconds: data.metadata?.elapsed_seconds,
      url: data.metadata?.url,
      error: data.metadata?.error,
    })
  } catch {
    // Debug tracing must never break the stream.
  }
}
