import { ref, type Ref } from 'vue'
import { ApiError } from '@/services/api/httpClient'
import {
  cancelResearch,
  fetchResearchCapabilities,
  fetchResearchEvents,
  fetchResearchRun,
  startResearch,
  type ResearchCapabilities,
} from '@/services/api/researchApi'
import {
  applyResearchFrame,
  emptyProgress,
  isTerminalStatus,
  parseResearchSse,
  progressFromRun,
  type ResearchProgress,
} from '@/services/chat/stream/handlers/research'
import { useChatsStore } from './chats'
import { useHistoryStore } from './history'
import { useIncognitoStore } from './incognito'

const POLL_MS = 2000

export interface ResearchBag {
  available: Ref<boolean | null>
  capabilities: Ref<ResearchCapabilities | null>
  composerOpen: Ref<boolean>
  starting: Ref<boolean>
  stopping: Ref<boolean>
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
    composerOpen: ref(false),
    starting: ref(false),
    stopping: ref(false),
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

export function openComposer(bag: ResearchBag): void {
  bag.startError.value = null
  bag.composerOpen.value = true
}

export function closeComposer(bag: ResearchBag): void {
  bag.composerOpen.value = false
  bag.startError.value = null
}

export function attachChat(bag: ResearchBag, chatId: number | null): void {
  bag.boundChatId = chatId
  if (chatId === null) {
    bag.progress.value = null
    stopPoll(bag)
    return
  }
  if (bag.progress.value?.chatId === chatId) {
    if (!isTerminalStatus(bag.progress.value.status)) startPoll(bag)
    return
  }
  const saved = readSaved(chatId)
  if (saved === null) {
    bag.progress.value = null
    stopPoll(bag)
    return
  }
  void resume(bag, chatId, saved)
}

export async function beginResearch(
  bag: ResearchBag,
  input: { question: string; depth: 'standard' | 'thorough'; web: boolean; knowledge: boolean }
): Promise<void> {
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
  const current = bag.progress.value
  if (current === null || isTerminalStatus(current.status) || bag.stopping.value) return
  bag.stopping.value = true
  try {
    const run = await cancelResearch(current.id)
    bag.progress.value = progressFromRun(current.chatId, run)
    stopPoll(bag)
    await reloadChat(current.chatId)
  } catch {
    bag.startError.value = 'stopFailed'
  } finally {
    bag.stopping.value = false
  }
}

export function dismissResearch(bag: ResearchBag): void {
  const current = bag.progress.value
  if (current !== null) localStorage.removeItem(storageKey(current.chatId))
  bag.progress.value = null
  stopPoll(bag)
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

async function acceptRun(
  bag: ResearchBag,
  chatId: number,
  input: { question: string; depth: 'standard' | 'thorough'; web: boolean; knowledge: boolean }
): Promise<void> {
  const accepted = await startResearch({
    question: input.question,
    depth: input.depth,
    chat_id: chatId,
    sources: { web: input.web, knowledge: input.knowledge },
  })
  const next = emptyProgress(accepted.id, chatId, input.question)
  next.status = accepted.status
  bag.progress.value = next
  localStorage.setItem(storageKey(chatId), accepted.id)
  bag.composerOpen.value = false
  startPoll(bag)
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
  bag.pollTimer = window.setInterval(() => {
    void tick(bag)
  }, POLL_MS)
}

function stopPoll(bag: ResearchBag): void {
  if (bag.pollTimer === null) return
  window.clearInterval(bag.pollTimer)
  bag.pollTimer = null
}

async function tick(bag: ResearchBag): Promise<void> {
  const current = bag.progress.value
  if (current === null || isTerminalStatus(current.status)) {
    stopPoll(bag)
    return
  }
  try {
    const next = await framesSince(bag, current)
    if (next === null || bag.progress.value?.id !== current.id) return
    if (isTerminalStatus(bag.progress.value.status)) return
    bag.progress.value = next
    if (!isTerminalStatus(next.status)) return
    await settle(bag, next)
  } catch {
    // The next tick asks again. Closing the page does not stop the run.
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
  let next = current
  for (const frame of parseResearchSse(body)) next = applyResearchFrame(next, frame)
  if (bag.progress.value?.id !== current.id || isTerminalStatus(bag.progress.value.status)) {
    return bag.progress.value
  }
  return next
}

async function settle(bag: ResearchBag, current: ResearchProgress): Promise<void> {
  stopPoll(bag)
  const run = await fetchResearchRun(current.id)
  if (bag.progress.value?.id !== current.id) return
  bag.progress.value = progressFromRun(current.chatId, run)
  await reloadChat(current.chatId)
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
