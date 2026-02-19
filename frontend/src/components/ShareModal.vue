<template>
  <Teleport to="#app">
    <Transition name="modal">
      <div
        v-if="isOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
        data-testid="modal-share-root"
        @click.self="close"
      >
        <div
          class="surface-elevated w-full max-w-2xl p-6 m-4 max-h-[90vh] overflow-y-auto"
          data-testid="modal-share"
        >
          <!-- Header -->
          <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold txt-primary">Share File</h2>
            <button
              class="p-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-secondary"
              data-testid="btn-file-share-close"
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
            <!-- File Info -->
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
                    d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                  />
                </svg>
                <div class="flex-1 min-w-0">
                  <div class="font-medium txt-primary truncate">{{ filename }}</div>
                  <div class="text-sm txt-secondary">File ID: {{ fileId }}</div>
                </div>
              </div>
            </div>

            <!-- Not Public Yet -->
            <div v-if="!shareInfo?.is_public" class="space-y-4">
              <p class="txt-secondary">
                This file is currently
                <span class="font-semibold text-[var(--brand)]">private</span>. Make it public to
                generate a shareable link.
              </p>

              <!-- Expiry Selector -->
              <div>
                <label class="block text-sm font-medium txt-primary mb-2"> Link expires in </label>
                <div class="grid grid-cols-2 gap-3">
                  <button
                    v-for="option in expiryOptions"
                    :key="option.value"
                    :class="[
                      'p-3 rounded-lg border-2 transition-all text-left',
                      selectedExpiry === option.value
                        ? 'border-[var(--brand)] bg-[var(--brand)]/10'
                        : 'border-light-border dark:border-dark-border hover:border-[var(--brand)]/50',
                    ]"
                    @click="selectedExpiry = option.value"
                  >
                    <div class="font-medium txt-primary">{{ option.label }}</div>
                    <div class="text-sm txt-secondary">{{ option.description }}</div>
                  </button>
                </div>
              </div>

              <!-- Make Public Button -->
              <button
                :disabled="sharing"
                class="btn-primary w-full py-3 rounded-lg font-medium disabled:opacity-50"
                data-testid="btn-file-share-make-public"
                @click="makePublic"
              >
                <span v-if="sharing">Generating link...</span>
                <span v-else>Make Public & Generate Link</span>
              </button>
            </div>

            <!-- Already Public -->
            <div v-else class="space-y-4">
              <div class="flex items-center gap-2 text-green-600 dark:text-green-400">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                  />
                </svg>
                <span class="font-semibold">File is public</span>
              </div>

              <!-- Share Link -->
              <div class="p-4 rounded-lg bg-black/5 dark:bg-white/5 space-y-3">
                <div class="flex items-center justify-between">
                  <span class="text-sm font-medium txt-secondary">Public Link</span>
                  <button
                    class="flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 transition-colors txt-primary text-sm"
                    data-testid="btn-file-share-copy"
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
                    {{ copied ? 'Copied!' : 'Copy' }}
                  </button>
                </div>
                <div
                  class="p-3 rounded bg-white dark:bg-black/20 font-mono text-sm break-all txt-primary"
                >
                  {{ fullShareUrl }}
                </div>
              </div>

              <!-- Expiry Info -->
              <div v-if="shareInfo?.expires_at" class="text-sm txt-secondary">
                <svg
                  class="w-4 h-4 inline mr-1"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                  />
                </svg>
                Expires: {{ formatDate(shareInfo.expires_at) }}
                <span
                  v-if="shareInfo.is_expired"
                  class="ml-2 text-red-600 dark:text-red-400 font-semibold"
                >
                  (Expired)
                </span>
              </div>

              <!-- Revoke Button -->
              <button
                :disabled="revoking"
                class="w-full py-2 rounded-lg border border-red-500 text-red-600 dark:text-red-400 hover:bg-red-500/10 transition-colors disabled:opacity-50"
                data-testid="btn-file-share-revoke"
                @click="revoke"
              >
                <span v-if="revoking">Revoking...</span>
                <span v-else>Revoke Public Access</span>
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
import * as filesService from '@/services/filesService'
import { useNotification } from '@/composables/useNotification'
import { useConfigStore } from '@/stores/config'

const { success: showSuccess, error: showError } = useNotification()
const config = useConfigStore()

interface Props {
  isOpen: boolean
  fileId: number | null
  filename?: string
}

const props = withDefaults(defineProps<Props>(), {
  filename: 'Unknown file',
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
const selectedExpiry = ref(7)
const shareInfo = ref<{
  is_public: boolean
  share_url: string | null
  share_token: string | null
  expires_at: number | null
  is_expired: boolean
} | null>(null)

const expiryOptions = [
  { value: 7, label: '7 Days', description: 'Expires in a week' },
  { value: 30, label: '30 Days', description: 'Expires in a month' },
  { value: 90, label: '90 Days', description: 'Expires in 3 months' },
  { value: 0, label: 'Never', description: 'No expiration' },
]

const fullShareUrl = computed(() => {
  if (!shareInfo.value?.share_url) return ''
  return config.appBaseUrl + shareInfo.value.share_url
})

watch(
  () => props.isOpen,
  async (open) => {
    if (open && props.fileId) {
      await loadShareInfo()
    }
    copied.value = false
  }
)

const loadShareInfo = async () => {
  if (!props.fileId) return

  loading.value = true
  try {
    shareInfo.value = await filesService.getShareInfo(props.fileId)
  } catch (error) {
    console.error('Failed to load share info:', error)
    showError('Failed to load share information')
  } finally {
    loading.value = false
  }
}

const makePublic = async () => {
  if (!props.fileId) return

  sharing.value = true
  try {
    const result = await filesService.shareFile(props.fileId, selectedExpiry.value)
    shareInfo.value = {
      is_public: result.is_public,
      share_url: result.share_url,
      share_token: result.share_token,
      expires_at: result.expires_at,
      is_expired: false,
    }
    showSuccess('File is now public!')
    emit('shared')
  } catch (error: any) {
    console.error('Failed to share file:', error)
    // Don't show error toast if it's an auth error (redirecting to login)
    if (error.message !== 'Authentication required') {
      showError('Failed to make file public')
    }
  } finally {
    sharing.value = false
  }
}

const revoke = async () => {
  if (!props.fileId) return

  revoking.value = true
  try {
    await filesService.unshareFile(props.fileId)
    shareInfo.value = {
      is_public: false,
      share_url: null,
      share_token: null,
      expires_at: null,
      is_expired: false,
    }
    showSuccess('Public access revoked')
    emit('unshared')
  } catch (error: any) {
    console.error('Failed to revoke share:', error)
    if (error.message !== 'Authentication required') {
      showError('Failed to revoke access')
    }
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
  } catch (error) {
    showError('Failed to copy link')
  }
}

const formatDate = (timestamp: number): string => {
  return new Date(timestamp * 1000).toLocaleString()
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
