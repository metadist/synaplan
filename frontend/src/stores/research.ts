import { defineStore } from 'pinia'
import {
  attachChat,
  beginResearch,
  closeComposer,
  createResearchBag,
  dismissResearch,
  ensureCapabilities,
  openComposer,
  pauseResearch,
  stopResearch,
} from './researchSession'

export const useResearchStore = defineStore('research', () => {
  const bag = createResearchBag()

  return {
    available: bag.available,
    capabilities: bag.capabilities,
    composerOpen: bag.composerOpen,
    starting: bag.starting,
    stopping: bag.stopping,
    startError: bag.startError,
    progress: bag.progress,
    ensureCapabilities: () => ensureCapabilities(bag),
    openComposer: () => openComposer(bag),
    closeComposer: () => closeComposer(bag),
    attachChat: (chatId: number | null) => attachChat(bag, chatId),
    begin: (input: {
      question: string
      depth: 'standard' | 'thorough'
      web: boolean
      knowledge: boolean
    }) => beginResearch(bag, input),
    stop: () => stopResearch(bag),
    dismiss: () => dismissResearch(bag),
    pause: () => pauseResearch(bag),
  }
})
