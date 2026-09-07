<template>
  <aside
    class="surface-card rounded-xl p-5 flex flex-col min-h-[20rem]"
    data-testid="section-test-panel"
  >
    <h2 class="txt-primary font-medium">{{ $t('assistants.tryDraft') }}</h2>
    <p class="txt-secondary text-sm mt-1 mb-3">{{ $t('assistants.tryDraftHint') }}</p>
    <p v-if="usingDefaultChat" class="text-xs txt-secondary mb-2" data-testid="text-model-fallback">
      {{ $t('assistants.usingDefaultChat') }}
    </p>
    <div class="flex-1 space-y-2 overflow-y-auto mb-3" data-testid="list-test-messages">
      <p
        v-for="(line, index) in lines"
        :key="index"
        class="text-sm txt-primary whitespace-pre-wrap"
      >
        {{ line }}
      </p>
    </div>
    <form class="flex gap-2" @submit.prevent="send">
      <input
        v-model="draft"
        type="text"
        class="flex-1"
        :placeholder="$t('assistants.tryDraftPlaceholder')"
        data-testid="input-test-message"
      />
      <button
        type="submit"
        class="btn-primary px-4 py-2.5 rounded-lg text-sm font-medium"
        :disabled="sending || !draft.trim()"
        data-testid="btn-test-send"
      >
        {{ $t('assistants.send') }}
      </button>
    </form>
  </aside>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { chatApi } from '@/services/api/chatApi'
import { useAgentsStore } from '@/stores/agents'

const store = useAgentsStore()
const authStore = useAuthStore()
const draft = ref('')
const sending = ref(false)
const lines = ref<string[]>([])

const usingDefaultChat = computed(() => {
  const chat = store.current?.draft?.models.chat
  return chat == null || chat === ''
})

function send(): void {
  const userId = authStore.user?.id
  const agentId = store.current?.id
  const message = draft.value.trim()
  if (!userId || !agentId || !message || sending.value) {
    return
  }
  draft.value = ''
  lines.value.push(message)
  sending.value = true
  let reply = ''
  chatApi.streamMessage({
    userId,
    message,
    agentId,
    draft: true,
    incognito: true,
    history: [],
    onUpdate: (data) => {
      if (data.status === 'data' && data.chunk) {
        reply += data.chunk
      }
      if (data.status === 'complete' || data.status === 'error') {
        if (reply) {
          lines.value.push(reply)
        }
        sending.value = false
      }
    },
  })
}
</script>
