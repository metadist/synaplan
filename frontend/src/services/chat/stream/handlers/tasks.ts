/**
 * The task cards of a multi-node plan (multitask routing): one card per node,
 * each moving through its states as the plan runs. A single-node turn never
 * emits these events.
 */
import { isTaskCardKind, isTaskCardState, type TaskCard } from '@/stores/history'
import type { StreamEventMetadata } from '@/types/chatStream'
import { normalizeMediaUrl } from '@/utils/urlHelper'
import { cardTraceFrom } from '@/utils/cardTrace'
import { parseFolderRead } from '@/utils/folderRead'
import { findTaskCard, isAskUserPayload } from '../messageUpdates'
import type { StreamHandlers, StreamStatusHandler, StreamTurn } from '../streamTurn'

const plan: StreamStatusHandler<StreamTurn> = (data, turn) => {
  const message = turn.message()
  const tasks = data.metadata?.plan
  if (!message || !Array.isArray(tasks) || tasks.length === 0) return

  turn.status.clear()
  message.wasMultitask = true
  message.taskPlan = {
    active: true,
    // On the plan, so a card's Stop reaches the backend even after a racing
    // handler cleared the turn id (#1141).
    trackId: turn.run.trackId(),
    replyNode: typeof data.metadata?.reply_node === 'string' ? data.metadata.reply_node : '',
    cards: tasks.map((task) => ({
      nodeId: task.node_id,
      capability: task.capability,
      kind: isTaskCardKind(task.kind) ? task.kind : 'text',
      state: 'pending' as const,
    })),
  }
}

/** The plan failed as a whole and the backend answers in one bubble: the failed cards would mislead. */
const planDiscarded: StreamStatusHandler<StreamTurn> = (_data, turn) => {
  const message = turn.message()
  if (message) {
    message.taskPlan = null
    message.wasMultitask = false
  }
}

function applyStepTrace(card: TaskCard, meta: StreamEventMetadata): void {
  if (typeof meta.error === 'string' && meta.error) card.error = meta.error
  if (typeof meta.text === 'string' && meta.text) card.text = meta.text
  if (typeof meta.prompt === 'string' && meta.prompt) card.prompt = meta.prompt
  if (typeof meta.query === 'string' && meta.query) card.query = meta.query
  if (typeof meta.results_count === 'number') card.resultsCount = meta.results_count
  if (meta.used_workspace === true) card.usedWorkspace = true
  Object.assign(card, cardTraceFrom(meta))
  if (isAskUserPayload(meta.ask_user)) card.askUser = meta.ask_user
}

/** A step the user cancelled stays cancelled: the backend's `failed` that follows is not an error. */
const taskUpdate: StreamStatusHandler<StreamTurn> = (data, turn) => {
  turn.ingestApprovalRequired(data)
  const card = findTaskCard(turn.message(), data.metadata?.node_id)
  if (!card || card.state === 'cancelled' || !isTaskCardState(data.metadata?.state)) return

  card.state = data.metadata.state
  if (data.metadata.state !== 'running') delete card.retrying
  applyStepTrace(card, data.metadata)
  if (data.metadata.retrying === true) card.retrying = true
}

const taskChunk: StreamStatusHandler<StreamTurn> = (data, turn) => {
  const card = findTaskCard(turn.message(), data.metadata?.node_id)
  if (card && typeof data.metadata?.chunk === 'string') {
    card.text = (card.text ?? '') + data.metadata.chunk
  }
}

const taskFile: StreamStatusHandler<StreamTurn> = (data, turn) => {
  const card = findTaskCard(turn.message(), data.metadata?.node_id)
  if (card && typeof data.metadata?.url === 'string') {
    card.url = normalizeMediaUrl(data.metadata.url)
    card.mediaType = typeof data.metadata?.type === 'string' ? data.metadata.type : card.kind
  }
}

/** Live progress: the moving bar of a render, or "n of m" files of a folder read. */
const taskProgress: StreamStatusHandler<StreamTurn> = (data, turn) => {
  const card = findTaskCard(turn.message(), data.metadata?.node_id)
  if (!card || card.state === 'cancelled') return

  const meta = data.metadata ?? {}
  if (typeof meta.percent === 'number') card.progressPercent = meta.percent
  if (typeof meta.provider_status === 'string') card.providerStatus = meta.provider_status
  if (typeof meta.elapsed_seconds === 'number') card.elapsedSeconds = meta.elapsed_seconds
  const folderRead = parseFolderRead(meta.folder_read)
  if (folderRead) card.folderRead = folderRead
}

export const taskHandlers: StreamHandlers<StreamTurn> = {
  plan,
  plan_discarded: planDiscarded,
  task_update: taskUpdate,
  task_chunk: taskChunk,
  task_file: taskFile,
  task_progress: taskProgress,
}
