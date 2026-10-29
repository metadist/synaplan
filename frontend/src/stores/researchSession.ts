import { ref, type Ref } from 'vue'
import { ApiError } from '@/services/api/httpClient'
import {
  answerNowResearch,
  cancelResearch,
  fetchResearchCapabilities,
  fetchResearchEvents,
  fetchResearchRun,
  startResearch,
  type ResearchCapabilities,
} from '@/services/api/researchApi'
import {
  applyResearchSse,
  emptyProgress,
  isTerminalStatus,
  progressFromRun,
  type ResearchProgress,
} from '@/services/chat/stream/handlers/research'
import { createUuid } from '@/utils/uuid'
import { useChatsStore } from './chats'
import { useHistoryStore } from './history'
import { useIncognitoStore } from './incognito'

const POLL_MS = 1200
/** While the writer streams the draft or the lead its note, the person reads along, so the poll keeps pace. */
const POLL_WRITING_MS = 700
/** The server refuses a start with more starting pages than this. */
const MAX_SEED_SOURCES = 50

/** What Look deeper sends: the question, the answer's cited pages, and the last turns so the research knows the chat. */
export interface LookDeeperInput {
  question: string
  context: Array<{ role: 'user' | 'assistant'; text: string }>
  seedSources: Array<{ url: string; title?: string }>
}

export interface ResearchBag {
  available: Ref<boolean | null>
  capabilities: Ref<ResearchCapabilities | null>
  starting: Ref<boolean>
  stopping: Ref<boolean>
  /** Answer now was accepted for the run now showing. Stays until another run starts. */
  answerNowAccepted: Ref<boolean>
  startError: Ref<string | null>
  progress: Ref<ResearchProgress | null>
  capabilitiesTask: Promise<void> | null
  pollTimer: number | null
  boundChatId: number | null
}

export function createResearchBag(): ResearchBag {
  return {
    available: ref(null),
    capabilities: ref(null),
    starting: ref(false),
    stopping: ref(false),
    answerNowAccepted: ref(false),
    startError: ref(null),
    progress: ref(null),
    capabilitiesTask: null,
    pollTimer: null,
    boundChatId: null,
  }
}

export async function ensureCapabilities(bag: ResearchBag): Promise<void> {
  if (bag.available.value !== null) return
  if (!bag.capabilitiesTask) bag.capabilitiesTask = loadCapabilities(bag)
  await bag.capabilitiesTask
}

/** Forget what the server said, so the next question asks again (an admin flipped a switch). */
export function forgetCapabilities(bag: ResearchBag): void {
  bag.available.value = null
  bag.capabilities.value = null
  bag.capabilitiesTask = null
}

export function attachChat(bag: ResearchBag, chatId: number | null): void {
  bag.boundChatId = chatId
  if (chatId === null) {
    bag.progress.value = null
    bag.answerNowAccepted.value = false
    stopPoll(bag)
    return
  }
  if (bag.progress.value?.chatId === chatId) {
    if (!isTerminalStatus(bag.progress.value.status)) startPoll(bag)
    return
  }
  bag.progress.value = null
  bag.answerNowAccepted.value = false
  stopPoll(bag)
  const saved = readSaved(chatId)
  if (saved !== null) void resume(bag, chatId, saved)
}

/**
 * The chat started a research on its own (`research_started` on the stream):
 * follow it at once, the same way a run of our own is followed. The reply
 * bubble that carries the run id shows what it does.
 */
export function adoptRun(bag: ResearchBag, chatId: number, id: string, question: string): void {
  bag.startError.value = null
  bag.answerNowAccepted.value = false
  localStorage.setItem(storageKey(chatId), id)
  if (bag.boundChatId !== chatId) return
  bag.progress.value = emptyProgress(id, chatId, question)
  startPoll(bag)
}

/**
 * A reply that was found after the fact — a reload, another device — still
 * running: follow it from where it is. A finished one is left alone.
 */
export async function followRun(bag: ResearchBag, chatId: number, id: string): Promise<void> {
  if (bag.progress.value?.id === id) return
  bag.answerNowAccepted.value = false
  const run = await fetchResearchRun(id)
  if (isTerminalStatus(run.status) || bag.boundChatId !== chatId) return
  bag.progress.value = progressFromRun(chatId, run)
  localStorage.setItem(storageKey(chatId), id)
  startPoll(bag)
}

/** Look deeper under a direct answer: a research sized by its own plan, told the person wants more. */
export async function lookDeeper(bag: ResearchBag, input: LookDeeperInput): Promise<void> {
  bag.startError.value = null
  const chatId = await chatForResearch()
  if (chatId === null) {
    bag.startError.value = useIncognitoStore().active ? 'incognito' : 'noChat'
    return
  }
  bag.starting.value = true
  try {
    await acceptRun(bag, chatId, input)
  } catch {
    bag.startError.value = 'startFailed'
  } finally {
    bag.starting.value = false
  }
}

export async function stopResearch(bag: ResearchBag): Promise<void> {
  await endRun(bag, cancelResearch, 'stopFailed')
}

/** Answer now: the report from what was read, or the plain answer when nothing was read yet. */
export async function answerNow(bag: ResearchBag): Promise<void> {
  const accepted = await endRun(bag, answerNowResearch, 'answerNowFailed')
  if (accepted) bag.answerNowAccepted.value = true
}

async function endRun(
  bag: ResearchBag,
  send: (id: string) => Promise<Parameters<typeof progressFromRun>[1]>,
  failure: string
): Promise<boolean> {
  const current = bag.progress.value
  if (current === null || isTerminalStatus(current.status) || bag.stopping.value) return false
  bag.stopping.value = true
  try {
    const run = await send(current.id)
    if (bag.progress.value?.id === current.id) {
      bag.progress.value = withSteps(progressFromRun(current.chatId, run), bag.progress.value)
      if (isTerminalStatus(run.status)) stopPoll(bag)
      else startPoll(bag)
    }
    if (isTerminalStatus(run.status)) await reloadChat(current.chatId)
    return true
  } catch {
    bag.startError.value = failure
    return false
  } finally {
    bag.stopping.value = false
  }
}

export function pauseResearch(bag: ResearchBag): void {
  stopPoll(bag)
}

async function loadCapabilities(bag: ResearchBag): Promise<void> {
  try {
    const described = await fetchResearchCapabilities()
    bag.capabilities.value = described
    bag.available.value = described.enabled === true
  } catch {
    bag.capabilities.value = null
    bag.available.value = false
  }
}

async function chatForResearch(): Promise<number | null> {
  if (useIncognitoStore().active) return null
  const chats = useChatsStore()
  if (chats.activeChatId !== null) return chats.activeChatId
  const chat = await chats.findOrCreateEmptyChat()
  return chat?.id ?? null
}

async function acceptRun(bag: ResearchBag, chatId: number, input: LookDeeperInput): Promise<void> {
  const capabilities = bag.capabilities.value
  const accepted = await startResearch({
    question: input.question,
    depth: 'auto',
    hint: 'deeper',
    chat_id: chatId,
    sources: {
      web: capabilities?.web_search_available ?? true,
      knowledge: capabilities?.knowledge_available ?? false,
    },
    context: { turns: input.context.slice(-6) },
    seed_sources: input.seedSources.slice(0, MAX_SEED_SOURCES),
  })
  const next = emptyProgress(accepted.id, chatId, input.question)
  next.status = accepted.status
  localStorage.setItem(storageKey(chatId), accepted.id)
  if (bag.boundChatId !== chatId) return
  bag.answerNowAccepted.value = false
  bag.progress.value = next
  addReplyFor(chatId, accepted.id)
  startPoll(bag)
}

/**
 * The bubble a Look deeper run works in: an empty reply bound to the run,
 * until the reload after the run brings the persisted one with the report.
 */
function addReplyFor(chatId: number, runId: string): void {
  if (useChatsStore().activeChatId !== chatId) return
  useHistoryStore().messages.push({
    id: createUuid(),
    role: 'assistant',
    parts: [],
    timestamp: new Date(),
    researchRunId: runId,
  })
}

async function resume(bag: ResearchBag, chatId: number, id: string): Promise<void> {
  try {
    const run = await fetchResearchRun(id)
    if (bag.boundChatId !== chatId) return
    bag.progress.value = progressFromRun(chatId, run)
    if (isTerminalStatus(run.status)) {
      stopPoll(bag)
      await reloadChat(chatId)
      return
    }
    startPoll(bag)
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      localStorage.removeItem(storageKey(chatId))
    }
    if (bag.boundChatId === chatId) bag.progress.value = null
  }
}

function startPoll(bag: ResearchBag): void {
  stopPoll(bag)
  void tick(bag)
}

function stopPoll(bag: ResearchBag): void {
  if (bag.pollTimer === null) return
  window.clearTimeout(bag.pollTimer)
  bag.pollTimer = null
}

/** The next tick is set once this one has answered, at the pace the run's phase asks for. */
function scheduleTick(bag: ResearchBag): void {
  if (bag.pollTimer !== null) return
  const current = bag.progress.value
  const streaming = current?.status === 'writing' || (current?.thinking ?? '') !== ''
  const delay = streaming ? POLL_WRITING_MS : POLL_MS
  bag.pollTimer = window.setTimeout(() => {
    bag.pollTimer = null
    void tick(bag)
  }, delay)
}

async function tick(bag: ResearchBag): Promise<void> {
  const current = bag.progress.value
  if (current === null || isTerminalStatus(current.status)) {
    stopPoll(bag)
    return
  }
  try {
    const next = await framesSince(bag, current)
    const shown = bag.progress.value
    if (next !== null && shown?.id === current.id && !isTerminalStatus(shown.status)) {
      bag.progress.value = next
      if (isTerminalStatus(next.status)) {
        await settle(bag, next)
        return
      }
    }
  } catch {
    // The next tick asks again. Closing the page does not stop the run.
  }
  if (bag.progress.value?.id === current.id && !isTerminalStatus(bag.progress.value.status)) {
    scheduleTick(bag)
  }
}

async function framesSince(
  bag: ResearchBag,
  current: ResearchProgress
): Promise<ResearchProgress | null> {
  const body = await fetchResearchEvents(current.id, current.lastSeq)
  if (bag.progress.value?.id !== current.id || isTerminalStatus(bag.progress.value.status)) {
    return bag.progress.value
  }
  const next = applyResearchSse(current, body)
  if (bag.progress.value?.id !== current.id || isTerminalStatus(bag.progress.value.status)) {
    return bag.progress.value
  }
  return next
}

async function settle(bag: ResearchBag, current: ResearchProgress): Promise<void> {
  stopPoll(bag)
  const run = await fetchResearchRun(current.id)
  if (bag.progress.value?.id !== current.id) return
  bag.progress.value = withSteps(progressFromRun(current.chatId, run), current)
  await reloadChat(current.chatId)
}

/** The run summary has no steps; the ones followed so far stay with it. */
function withSteps(summary: ResearchProgress, followed: ResearchProgress): ResearchProgress {
  summary.steps = followed.steps
  return summary
}

async function reloadChat(chatId: number): Promise<void> {
  if (useChatsStore().activeChatId !== chatId) return
  await useHistoryStore().loadMessages(chatId, 0, 50, true)
}

function readSaved(chatId: number): string | null {
  try {
    return localStorage.getItem(storageKey(chatId))
  } catch {
    return null
  }
}

function storageKey(chatId: number): string {
  return `synaplan.researchRun.${chatId}`
}
