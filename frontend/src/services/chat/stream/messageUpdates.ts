/**
 * Pure updates of one assistant message from stream payloads. None of them
 * touches the view: they read a payload and write the fields of the message
 * it belongs to, so every handler variant shares one copy.
 */
import { parsePlatformDocs } from '@/components/chat/refs/DocRefPill'
import type { Message, Part, TaskCard } from '@/stores/history'
import type { StreamGeneratedFile, StreamLinkItem, StreamUpdatePayload } from '@/types/chatStream'
import { isChannelSource } from '@/utils/channelSource'
import { parseMediaJobPayload } from '@/utils/messageMapper'
import type { StreamModelFallback } from './streamTurn'

export function applyDocsToMessage(message: Message, raw: unknown): void {
  const docs = parsePlatformDocs(raw)
  if (docs) {
    message.docs = docs
  }
}

export function applyDocumentComplete(message: Message, data: StreamUpdatePayload): void {
  if (Array.isArray(data.documentChanges)) {
    message.documentChanges = data.documentChanges
  }
  if (typeof data.documentVersion === 'number') {
    message.documentVersion = data.documentVersion
  }
  if (data.documentFidelityLossy === true) {
    message.documentFidelityLossy = true
  }
}

/**
 * Accepts the bare job payload (`{ job_id, type, state }`, sent on `complete`)
 * and a metadata envelope that nests it under `media_job` / `mediaJob` (sent
 * on `generating`), so the job banner can take over as early as possible.
 */
export function applyMediaJobToMessage(message: Message | undefined, raw: unknown): void {
  if (!message) return
  const parsed = mediaJobOf(raw)
  if (parsed) {
    message.mediaJob = parsed
  }
}

/** The inline "generating…" token for a media job; an unknown type falls back to video. */
export function generatingTokenForMediaJob(raw: unknown): string {
  switch (mediaJobOf(raw)?.type) {
    case 'image':
      return '__IMAGE_GENERATING__'
    case 'audio':
      return '__AUDIO_GENERATING__'
    default:
      return '__VIDEO_GENERATING__'
  }
}

function mediaJobOf(raw: unknown): ReturnType<typeof parseMediaJobPayload> {
  const parsed = parseMediaJobPayload(raw)
  if (parsed || !raw || typeof raw !== 'object') {
    return parsed
  }
  const envelope = raw as Record<string, unknown>
  return parseMediaJobPayload(envelope.media_job ?? envelope.mediaJob)
}

const isBadModelToken = (model: unknown): boolean =>
  model === undefined ||
  model === null ||
  String(model).toLowerCase() === 'error' ||
  // Channel tokens (`WHATSAPP`, `EMAIL`, …) leak in from inbound messages (#653).
  isChannelSource(typeof model === 'string' ? model : null)

const isBadProviderToken = (provider: unknown): boolean =>
  provider === undefined ||
  provider === null ||
  String(provider).toLowerCase() === 'system' ||
  isChannelSource(typeof provider === 'string' ? provider : null)

/**
 * Provider / model footer of the answer, in the shape the history API returns.
 * The flat fields fill `aiModels.chat` for older backends and error paths; the
 * audio and voice models pass through so the voice badge shows live (#583).
 */
export function applyAssistantChatModelFooter(
  message: Message,
  data: {
    provider?: string
    model?: string
    model_id?: number | null
    aiModels?: Message['aiModels'] | null
  },
  fallback: StreamModelFallback
): void {
  const model = !isBadModelToken(data.model)
    ? String(data.model)
    : fallback.model || message.modelLabel
  const provider = !isBadProviderToken(data.provider)
    ? String(data.provider)
    : fallback.provider || message.provider
  const modelId =
    data.model_id !== undefined && data.model_id !== null
      ? data.model_id
      : (fallback.model_id ?? null)
  const { chat, sorting, audio, voice } = data.aiModels ?? {}
  const extras = {
    ...(sorting ? { sorting } : {}),
    ...(audio ? { audio } : {}),
    ...(voice ? { voice } : {}),
  }

  if (model && provider) {
    message.modelLabel = model
    message.provider = provider
    message.aiModels = { chat: chat ?? { provider, model, model_id: modelId }, ...extras }
  } else if (chat || sorting || audio || voice) {
    message.aiModels = { ...(chat ? { chat } : {}), ...extras }
  }
}

export function isAskUserPayload(value: unknown): value is NonNullable<TaskCard['askUser']> {
  return (
    !!value &&
    typeof value === 'object' &&
    typeof (value as { question?: unknown }).question === 'string'
  )
}

export function findTaskCard(message: Message | undefined, nodeId: unknown): TaskCard | undefined {
  return message?.taskPlan?.cards.find((card) => card.nodeId === nodeId)
}

/** Reasoning (OpenAI o-series, GPT-5) streams into one thinking part, created on its first chunk (#1058). */
export function appendReasoning(message: Message, chunk: string, position: 'first' | 'last'): void {
  let part = message.parts.find(
    (candidate) => candidate.type === 'thinking' && candidate.isStreaming
  )
  if (!part) {
    part = { type: 'thinking', content: '', isStreaming: true, thinkingStartedAt: Date.now() }
    if ('first' === position) {
      message.parts.unshift(part)
    } else {
      message.parts.push(part)
    }
  }
  part.content += chunk
}

export function linksPart(links: StreamLinkItem[]): Part {
  return {
    type: 'links',
    items: links.map((link) => ({
      title: link.title || link.url,
      url: link.url,
      desc: link.description,
      host: hostOf(link.url),
    })),
  }
}

function hostOf(url: string): string {
  try {
    return new URL(url).hostname
  } catch {
    return url
  }
}

export function applySearchResults(message: Message, results: unknown, query: string): void {
  if (!Array.isArray(results) || results.length === 0) return
  message.searchResults = results as NonNullable<Message['searchResults']>
  message.webSearch = { query, resultsCount: results.length }
}

/** What the answer leaned on: sources, the request sent to the model, platform docs. */
export function applyAnswerSources(message: Message, data: StreamUpdatePayload): void {
  applySearchResults(message, data.searchResults, data.searchResults?.[0]?.query || '')
  if (Array.isArray(data.ragSources) && data.ragSources.length > 0) {
    message.ragSources = data.ragSources as NonNullable<Message['ragSources']>
  }
  const sent = data.providerMessages as
    { messages?: Message['providerMessages']; truncated?: boolean } | undefined
  if (sent && Array.isArray(sent.messages)) {
    message.providerMessages = sent.messages
    message.providerMessagesTruncated = sent.truncated === true
  }
  applyDocsToMessage(message, data.docs)
}

/**
 * Attach a generated document and replace the raw JSON or marker the model
 * produced with the translated confirmation.
 */
export function applyGeneratedFile(
  message: Message,
  file: StreamGeneratedFile,
  confirmation: string
): void {
  if (!message.files) {
    message.files = []
  }
  message.files.push({
    id: file.id,
    filename: file.filename,
    filePath: file.path,
    fileSize: file.size,
    fileType: file.type,
    fileMime: file.mime,
  })
  const first = message.parts[0]
  const showsEnvelope =
    message.parts.length === 0 ||
    (first.type === 'code' && first.content?.includes('BFILEPATH')) ||
    (first.type === 'text' && first.content?.includes('__FILE_GENERATED__'))
  if (showsEnvelope) {
    message.parts = [{ type: 'text', content: confirmation }]
  }
}

/** The topic routing chose, kept on the answer so Again picks the right model. */
export function applyRouting(message: Message, data: StreamUpdatePayload): void {
  if (data.topic) {
    message.topic = data.topic
  }
  if (data.originalTopic !== undefined) {
    message.originalTopic = data.originalTopic
  }
  if (data.originalMediaType !== undefined) {
    message.originalMediaType = data.originalMediaType
  }
}
