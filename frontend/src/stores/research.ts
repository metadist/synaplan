import { defineStore } from 'pinia'
import {
  adoptRun,
  answerNow,
  attachChat,
  createResearchBag,
  ensureCapabilities,
  followRun,
  forgetCapabilities,
  lookDeeper,
  pauseResearch,
  stopResearch,
  type LookDeeperInput,
} from './researchSession'

export const useResearchStore = defineStore('research', () => {
  const bag = createResearchBag()

  return {
    available: bag.available,
    capabilities: bag.capabilities,
    starting: bag.starting,
    stopping: bag.stopping,
    startError: bag.startError,
    progress: bag.progress,
    ensureCapabilities: () => ensureCapabilities(bag),
    forgetCapabilities: () => forgetCapabilities(bag),
    attachChat: (chatId: number | null) => attachChat(bag, chatId),
    adopt: (chatId: number, id: string, question: string) => adoptRun(bag, chatId, id, question),
    follow: (chatId: number, id: string) => followRun(bag, chatId, id),
    lookDeeper: (input: LookDeeperInput) => lookDeeper(bag, input),
    answerNow: () => answerNow(bag),
    stop: () => stopResearch(bag),
    pause: () => pauseResearch(bag),
  }
})
