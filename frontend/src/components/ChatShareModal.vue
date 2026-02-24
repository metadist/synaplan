<template>
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="isOpen"
        class="fixed inset-0 z-[110] flex items-center justify-center bg-black/50 backdrop-blur-sm"
        data-testid="modal-chat-share-root"
        @click.self="close"
      >
        <div
          class="surface-elevated w-full max-w-2xl p-6 m-4 max-h-[90vh] overflow-y-auto"
          data-testid="modal-chat-share"
        >
          <!-- Header -->
          <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold txt-primary">{{ $t('chatShare.title') }}</h2>
            <button
              class="p-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-secondary"
              data-testid="btn-chat-share-close"
              @click="close"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
            </button>
          </div>

          <!-- Loading State -->
          <div v-if="loading" class="flex justify-center py-8">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--brand)]"></div>
          </div>

          <!-- Content -->
          <div v-else>
            <!-- Chat Info -->
            <div class="mb-6 p-4 rounded-lg bg-black/5 dark:bg-white/5">
              <div class="flex items-center gap-3">
                <svg
                  class="w-8 h-8 text-[var(--brand)]"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                  />
                </svg>
                <div class="flex-1 min-w-0">
                  <div class="font-medium txt-primary truncate">{{ chatTitle }}</div>
                </div>
              </div>
            </div>

            <!-- Share error (deterministic for E2E) -->
            <div
              v-if="shareErrorMessage"
              class="p-4 rounded-lg bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400"
              data-testid="share-error"
            >
              {{ shareErrorMessage }}
            </div>

            <!-- Not Public Yet -->
            <div v-if="!shareInfo?.isShared && !shareErrorMessage" class="space-y-4">
              <p class="txt-secondary">
                {{ $t('chatShare.notPublic') }}
                <span class="font-semibold text-[var(--brand)]">{{ $t('chatShare.private') }}</span
                >.
                {{ $t('chatShare.makePublicDescription') }}
              </p>

              <!-- Benefits -->
              <div class="p-4 rounded-lg bg-[var(--brand)]/10 space-y-2">
                <div class="font-medium txt-primary">{{ $t('chatShare.benefits') }}</div>
                <ul class="text-sm txt-secondary space-y-1">
                  <li class="flex items-start gap-2">
                    <svg
                      class="w-4 h-4 mt-0.5 text-[var(--brand)]"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M5 13l4 4L19 7"
                      />
                    </svg>
                    <span>{{ $t('chatShare.benefit1') }}</span>
                  </li>
                  <li class="flex items-start gap-2">
                    <svg
                      class="w-4 h-4 mt-0.5 text-[var(--brand)]"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M5 13l4 4L19 7"
                      />
                    </svg>
                    <span>{{ $t('chatShare.benefit2') }}</span>
                  </li>
                  <li class="flex items-start gap-2">
                    <svg
                      class="w-4 h-4 mt-0.5 text-[var(--brand)]"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M5 13l4 4L19 7"
                      />
                    </svg>
                    <span>{{ $t('chatShare.benefit3') }}</span>
                  </li>
                </ul>
              </div>

              <!-- Make Public Button -->
              <button
                :disabled="sharing"
                class="btn-primary w-full py-3 rounded-lg font-medium disabled:opacity-50"
                data-testid="btn-chat-share-make-public"
                @click="makePublic"
              >
                <span v-if="sharing">{{ $t('chatShare.generating') }}</span>
                <span v-else>{{ $t('chatShare.makePublic') }}</span>
              </button>
            </div>

            <!-- Already Public (E2E: share-done) -->
            <div v-else-if="shareInfo?.isShared" class="space-y-4" data-testid="share-done">
              <div class="flex items-center gap-2 text-green-600 dark:text-green-400">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                  />
                </svg>
                <span class="font-semibold">{{ $t('chatShare.isPublic') }}</span>
              </div>

              <!-- Share Link -->
              <div class="p-4 rounded-lg bg-black/5 dark:bg-white/5 space-y-3">
                <div class="flex items-center justify-between">
                  <span class="text-sm font-medium txt-secondary">{{
                    $t('chatShare.publicLink')
                  }}</span>
                  <button
                    class="flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-primary text-sm"
                    data-testid="btn-chat-share-copy"
                    @click="copyLink"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"
                      />
                    </svg>
                    {{ copied ? $t('chatShare.copied') : $t('chatShare.copy') }}
                  </button>
                </div>
                <div
                  data-testid="share-link-input"
                  class="p-3 rounded bg-white dark:bg-black/20 font-mono text-sm break-all txt-primary"
                >
                  {{ fullShareUrl }}
                </div>
              </div>

              <!-- SEO Info -->
              <div class="p-4 rounded-lg bg-blue-500/10 border border-blue-500/20">
                <div class="flex items-start gap-3">
                  <svg
                    class="w-5 h-5 text-blue-600 dark:text-blue-400 mt-0.5"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                    />
                  </svg>
                  <div class="flex-1">
                    <div class="font-medium txt-primary text-sm mb-1">
                      {{ $t('chatShare.seoTitle') }}
                    </div>
                    <div class="text-sm txt-secondary">{{ $t('chatShare.seoDescription') }}</div>
                  </div>
                </div>
              </div>

              <!-- Revoke Button -->
              <button
                :disabled="revoking"
                class="w-full py-2 rounded-lg border border-red-500 text-red-600 dark:text-red-400 hover:bg-red-500/10 transition-colors disabled:opacity-50"
                data-testid="btn-chat-share-revoke"
                @click="revoke"
              >
                <span v-if="revoking">{{ $t('chatShare.revoking') }}</span>
                <span v-else>{{ $t('chatShare.revoke') }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useChatsStore } from '@/stores/chats'
import { useNotification } from '@/composables/useNotification'

const { success: showSuccess, error: showError } = useNotification()
const chatsStore = useChatsStore()

interface Props {
  isOpen: boolean
  chatId: number | null
  chatTitle?: string
}

const props = withDefaults(defineProps<Props>(), {
  chatTitle: 'Chat',
})

const emit = defineEmits<{
  close: []
  shared: []
  unshared: []
}>()

const loading = ref(false)
const sharing = ref(false)
const revoking = ref(false)
const copied = ref(false)
const shareErrorMessage = ref('')
const shareInfo = ref<{
  isShared: boolean
  shareUrl: string | null
  shareToken: string | null
} | null>(null)

const fullShareUrl = computed(() => {
  if (!shareInfo.value?.shareUrl) return ''
  const baseUrl = window.location.origin
  return `${baseUrl}/shared/${shareInfo.value.shareToken}`
})

watch(
  () => props.isOpen,
  async (open) => {
    shareErrorMessage.value = ''
    if (open && props.chatId) {
      await loadShareInfo()
    }
    copied.value = false
  }
)

const loadShareInfo = async () => {
  if (!props.chatId) return

  loading.value = true
  try {
    shareInfo.value = await chatsStore.getShareInfo(props.chatId)
  } catch (error) {
    console.error('Failed to load share info:', error)
    shareErrorMessage.value = 'Failed to load share information'
    showError('Failed to load share information')
  } finally {
    loading.value = false
  }
}

const makePublic = async () => {
  if (!props.chatId) return

  sharing.value = true
  try {
    const result = await chatsStore.shareChat(props.chatId, true)
    if (!result) {
      shareErrorMessage.value = 'Unable to share chat. Please sign in again.'
      showError('Unable to share chat. Please sign in again.')
      return
    }
    shareErrorMessage.value = ''
    shareInfo.value = {
      isShared: result.isShared,
      shareUrl: result.shareUrl,
      shareToken: result.shareToken,
    }
    showSuccess('Chat is now public and will be indexed by Google!')
    emit('shared')
  } catch (error) {
    console.error('Failed to share chat:', error)
    shareErrorMessage.value = 'Failed to make chat public'
    showError('Failed to make chat public')
  } finally {
    sharing.value = false
  }
}

const revoke = async () => {
  if (!props.chatId) return

  revoking.value = true
  try {
    await chatsStore.shareChat(props.chatId, false)
    shareInfo.value = {
      isShared: false,
      shareUrl: null,
      shareToken: null,
    }
    showSuccess('Public access revoked')
    emit('unshared')
  } catch (error) {
    console.error('Failed to revoke share:', error)
    showError('Failed to revoke access')
  } finally {
    revoking.value = false
  }
}

const copyLink = async () => {
  try {
    await navigator.clipboard.writeText(fullShareUrl.value)
    copied.value = true
    showSuccess('Link copied to clipboard!')
    setTimeout(() => {
      copied.value = false
    }, 2000)
  } catch {
    showError('Failed to copy link')
  }
}

const close = () => {
  emit('close')
}
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-active .surface-elevated,
.modal-leave-active .surface-elevated {
  transition: transform 0.2s ease;
}

.modal-enter-from .surface-elevated,
.modal-leave-to .surface-elevated {
  transform: scale(0.95);
}
</style>
