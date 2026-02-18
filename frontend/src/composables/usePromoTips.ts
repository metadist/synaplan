import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useAppModeStore } from '@/stores/appMode'
import { useChatsStore } from '@/stores/chats'

export interface PromoTip {
  id: string
  icon: string
  titleKey: string
  descriptionKey: string
  actionKey: string
  actionRoute: string
  gradient: string
}

const STORAGE_KEY = 'synaplan_promo_tips'
const MIN_INTERVAL_MS = 5 * 60 * 1000
const MESSAGES_BEFORE_FIRST_TIP = 3

interface TipState {
  dismissed: string[]
  lastShown: number
  messagesSinceLastTip: number
  sessionTipCount: number
}

function loadState(): TipState {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) return JSON.parse(raw)
  } catch { /* ignore */ }
  return { dismissed: [], lastShown: 0, messagesSinceLastTip: 0, sessionTipCount: 0 }
}

function saveState(state: TipState) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state))
  } catch { /* ignore */ }
}

const allTips: PromoTip[] = [
  {
    id: 'chat-widget',
    icon: 'mdi:chat-processing-outline',
    titleKey: 'promoTips.chatWidget.title',
    descriptionKey: 'promoTips.chatWidget.description',
    actionKey: 'promoTips.chatWidget.action',
    actionRoute: '/tools/chat-widget',
    gradient: 'from-violet-500/10 to-blue-500/10 dark:from-violet-500/[0.07] dark:to-blue-500/[0.07]',
  },
  {
    id: 'files-upload',
    icon: 'mdi:file-document-plus-outline',
    titleKey: 'promoTips.filesUpload.title',
    descriptionKey: 'promoTips.filesUpload.description',
    actionKey: 'promoTips.filesUpload.action',
    actionRoute: '/files',
    gradient: 'from-emerald-500/10 to-teal-500/10 dark:from-emerald-500/[0.07] dark:to-teal-500/[0.07]',
  },
  {
    id: 'ai-config',
    icon: 'mdi:tune-variant',
    titleKey: 'promoTips.aiConfig.title',
    descriptionKey: 'promoTips.aiConfig.description',
    actionKey: 'promoTips.aiConfig.action',
    actionRoute: '/config/ai-models',
    gradient: 'from-orange-500/10 to-amber-500/10 dark:from-orange-500/[0.07] dark:to-amber-500/[0.07]',
  },
  {
    id: 'doc-summary',
    icon: 'mdi:text-box-search-outline',
    titleKey: 'promoTips.docSummary.title',
    descriptionKey: 'promoTips.docSummary.description',
    actionKey: 'promoTips.docSummary.action',
    actionRoute: '/tools/doc-summary',
    gradient: 'from-pink-500/10 to-rose-500/10 dark:from-pink-500/[0.07] dark:to-rose-500/[0.07]',
  },
  {
    id: 'upgrade-pro',
    icon: 'mdi:crown-outline',
    titleKey: 'promoTips.upgradePro.title',
    descriptionKey: 'promoTips.upgradePro.description',
    actionKey: 'promoTips.upgradePro.action',
    actionRoute: '/subscription',
    gradient: 'from-amber-500/10 to-yellow-500/10 dark:from-amber-500/[0.07] dark:to-yellow-500/[0.07]',
  },
  {
    id: 'file-mention',
    icon: 'mdi:at',
    titleKey: 'promoTips.fileMention.title',
    descriptionKey: 'promoTips.fileMention.description',
    actionKey: 'promoTips.fileMention.action',
    actionRoute: '',
    gradient: 'from-sky-500/10 to-cyan-500/10 dark:from-sky-500/[0.07] dark:to-cyan-500/[0.07]',
  },
]

export function usePromoTips() {
  const authStore = useAuthStore()
  const appModeStore = useAppModeStore()
  const chatsStore = useChatsStore()

  const state = ref(loadState())
  const currentTip = ref<PromoTip | null>(null)
  const isExpanded = ref(false)

  const availableTips = computed(() => {
    return allTips.filter((tip) => {
      if (state.value.dismissed.includes(tip.id)) return false

      switch (tip.id) {
        case 'chat-widget':
          return appModeStore.isAdvancedMode
        case 'ai-config':
          return appModeStore.isAdvancedMode
        case 'doc-summary':
          return appModeStore.isAdvancedMode
        case 'upgrade-pro':
          return !authStore.isPro && !authStore.isAdmin
        case 'files-upload':
          return true
        case 'file-mention':
          return true
        default:
          return true
      }
    })
  })

  function incrementMessageCount() {
    state.value.messagesSinceLastTip++
    saveState(state.value)
  }

  function shouldShowTip(): boolean {
    if (availableTips.value.length === 0) return false
    if (state.value.sessionTipCount >= 3) return false

    const totalMessages = chatsStore.chats.reduce((sum, c) => sum + (c.messageCount ?? 0), 0)
    if (totalMessages < MESSAGES_BEFORE_FIRST_TIP && state.value.sessionTipCount === 0) return false

    const now = Date.now()
    if (now - state.value.lastShown < MIN_INTERVAL_MS) return false

    const neededMessages = 5 + state.value.sessionTipCount * 3
    return state.value.messagesSinceLastTip >= neededMessages
  }

  function tryShowTip(): boolean {
    if (!shouldShowTip()) return false

    const tips = availableTips.value
    const tip = tips[Math.floor(Math.random() * tips.length)]
    if (!tip) return false

    currentTip.value = tip
    isExpanded.value = false
    state.value.lastShown = Date.now()
    state.value.messagesSinceLastTip = 0
    state.value.sessionTipCount++
    saveState(state.value)
    return true
  }

  function dismissTip(permanent = false) {
    if (currentTip.value && permanent) {
      state.value.dismissed.push(currentTip.value.id)
      saveState(state.value)
    }
    currentTip.value = null
    isExpanded.value = false
  }

  function toggleExpand() {
    isExpanded.value = !isExpanded.value
  }

  function onMessageSent() {
    incrementMessageCount()
    if (!currentTip.value) {
      tryShowTip()
    }
  }

  /**
   * Dev-only: force-show a specific tip or a random one.
   * Available on window.__synaplanPromo in DEV mode.
   */
  function forceShowTip(tipId?: string) {
    let tip: PromoTip | undefined
    if (tipId) {
      tip = allTips.find((t) => t.id === tipId)
      if (!tip) {
        console.warn(
          `[PromoTips] Unknown tip "${tipId}". Available:`,
          allTips.map((t) => t.id),
        )
        return
      }
    } else {
      tip = allTips[Math.floor(Math.random() * allTips.length)]
    }
    if (tip) {
      currentTip.value = tip
      isExpanded.value = false
      console.info(`[PromoTips] Force-showing: "${tip.id}"`)
    }
  }

  function resetState() {
    state.value = { dismissed: [], lastShown: 0, messagesSinceLastTip: 0, sessionTipCount: 0 }
    saveState(state.value)
    currentTip.value = null
    isExpanded.value = false
    console.info('[PromoTips] State reset')
  }

  if (import.meta.env.DEV) {
    ;(window as Record<string, unknown>).__synaplanPromo = {
      show: forceShowTip,
      reset: resetState,
      tips: allTips.map((t) => t.id),
    }
  }

  return {
    currentTip,
    isExpanded,
    onMessageSent,
    dismissTip,
    toggleExpand,
    tryShowTip,
    forceShowTip,
    resetState,
  }
}
