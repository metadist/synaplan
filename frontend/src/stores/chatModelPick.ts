import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Explicit chat-model pick for the composer.
 *
 * Null means the next message uses the default chat model of the active
 * model mix. The pick is session-local: switching chats and every successful
 * mix apply clear it, so a mix is never bypassed by a leftover selection.
 */
export const useChatModelPickStore = defineStore('chatModelPick', () => {
  const selectedModelId = ref<number | null>(null)

  const clear = () => {
    selectedModelId.value = null
  }

  return {
    selectedModelId,
    clear,
  }
})
