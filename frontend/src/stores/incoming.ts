import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import { iamApi, type IamSharedItem } from '@/services/api/iamApi'
import { isIamSharingEnabled } from '@/composables/useIamFeature'
import { useAuthStore } from '@/stores/auth'

/** The resource kind this store tracks; other kinds keep their own lists. */
export const INCOMING_KIND = 'conversation'

/**
 * "Incoming": conversations other people or groups shared with the signed-in
 * user, plus the "how many arrived since I last looked" counter behind the red
 * dot on the account button.
 *
 * Everything is gated on the IAM sharing flag and fails closed — with the
 * feature off (or a failing request) both lists are empty and no dot shows.
 */
export const useIncomingStore = defineStore('incoming', () => {
  const authStore = useAuthStore()

  const chats = ref<IamSharedItem[]>([])
  const unseenCount = ref(0)
  const loading = ref(false)
  const loaded = ref(false)

  const hasNew = computed(() => unseenCount.value > 0)
  const newChats = computed(() => chats.value.filter((c) => c.isNew))
  const chatIds = computed(() => new Set(chats.value.map((c) => Number(c.id))))

  /**
   * Whether `chatId` may be opened as the active chat even though it is not in
   * the user's own list: it is a known incoming chat, or the incoming list is
   * still on its way (sharing on, first load pending) so we must not discard
   * it yet. With sharing off this is always false and nothing changes.
   */
  function isOpenable(chatId: number): boolean {
    if (!isIamSharingEnabled()) return false
    return !loaded.value || chatIds.value.has(chatId)
  }

  /** De-duplicates concurrent loads (sheet + browser + view mount together). */
  let inFlight: Promise<void> | null = null

  async function load(): Promise<void> {
    if (!isIamSharingEnabled()) {
      chats.value = []
      unseenCount.value = 0
      loaded.value = true
      return
    }
    if (inFlight) return inFlight
    loading.value = true
    inFlight = (async () => {
      try {
        const [items, count] = await Promise.all([
          iamApi.listSharedWithMe(INCOMING_KIND),
          iamApi.countUnseenShared(INCOMING_KIND),
        ])
        chats.value = items
        unseenCount.value = count
      } catch {
        chats.value = []
        unseenCount.value = 0
      } finally {
        loading.value = false
        loaded.value = true
        inFlight = null
      }
    })()
    return inFlight
  }

  /** Cheap refresh of the counter only (used when the account menu opens). */
  async function refreshUnseen(): Promise<void> {
    if (!isIamSharingEnabled()) {
      unseenCount.value = 0
      return
    }
    try {
      unseenCount.value = await iamApi.countUnseenShared(INCOMING_KIND)
    } catch {
      unseenCount.value = 0
    }
  }

  /**
   * The user opened their incoming list: the dot goes away now, while the rows
   * keep their `isNew` marker until the next load so they can still be spotted.
   */
  async function markSeen(): Promise<void> {
    if (!isIamSharingEnabled()) return
    unseenCount.value = 0
    try {
      await iamApi.markSharedSeen(INCOMING_KIND)
    } catch {
      // The dot is already hidden for this session; the next load re-syncs.
    }
  }

  function reset(): void {
    chats.value = []
    unseenCount.value = 0
    loading.value = false
    loaded.value = false
  }

  // The red dot must be right on first paint, on every surface that mounts this
  // store, so the store itself follows sign-in and the sharing flag.
  watch(
    () => authStore.isAuthenticated && isIamSharingEnabled(),
    (ready) => {
      if (ready) void load()
      else reset()
    },
    { immediate: true }
  )

  return {
    chats,
    unseenCount,
    loading,
    loaded,
    hasNew,
    newChats,
    chatIds,
    isOpenable,
    load,
    refreshUnseen,
    markSeen,
    reset,
  }
})
