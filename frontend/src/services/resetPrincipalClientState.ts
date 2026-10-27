/**
 * Wipe the previous person's client state before a new session is shown.
 *
 * The same wipe `useAuthStore().login()` runs. It lives here because that
 * store function is at its size ceiling. A principal swap that skips it
 * leaves the previous chats, history, model pick and the stored active-chat
 * id in place.
 */
export async function resetPrincipalClientState(): Promise<void> {
  const [
    { useChatsStore },
    { useHistoryStore },
    { clearSseToken },
    { useMemoriesStore },
    { useFeedbackStore },
    { useChatModelPickStore },
    { useSmartSearchStore },
    { resetIncomingCount },
    { useCommandsStore },
    { useGuestStore },
  ] = await Promise.all([
    import('@/stores/chats'),
    import('@/stores/history'),
    import('@/services/api/chatApi'),
    import('@/stores/userMemories'),
    import('@/stores/userFeedback'),
    import('@/stores/chatModelPick'),
    import('@/stores/smartSearch'),
    import('@/composables/useLibraryLinks'),
    import('@/stores/commands'),
    import('@/stores/guest'),
  ])
  useChatsStore().$reset()
  useHistoryStore().clear()
  useMemoriesStore().$reset()
  useFeedbackStore().$reset()
  useChatModelPickStore().clear()
  useSmartSearchStore().reset()
  useCommandsStore().reset()
  resetIncomingCount()
  clearSseToken()
  useGuestStore().$reset()
}
