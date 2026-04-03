<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useFullscreenTeleportTarget } from '@/composables/useFullscreenTeleportTarget'

const STORAGE_KEY = 'synaplan-skip-external-link-warning'

interface Props {
  url: string
  isOpen: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
  close: []
}>()

const { t } = useI18n()
const { teleportTarget } = useFullscreenTeleportTarget()
const dontAskAgain = ref(false)

const hostname = computed(() => {
  try {
    return new URL(props.url).hostname
  } catch {
    return props.url
  }
})

function proceed() {
  if (dontAskAgain.value) {
    localStorage.setItem(STORAGE_KEY, 'true')
  }
  window.open(props.url, '_blank', 'noopener,noreferrer')
  emit('close')
}

function cancel() {
  emit('close')
}

function handleKeydown(event: KeyboardEvent) {
  if (!props.isOpen) return

  if (event.key === 'Escape') {
    event.preventDefault()
    cancel()
    return
  }

  if (event.key !== 'Enter') {
    return
  }

  const target = event.target as HTMLElement | null
  if (!target) {
    return
  }

  const tag = target.tagName
  if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'BUTTON' || tag === 'SELECT') {
    return
  }

  event.preventDefault()
  proceed()
}

onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', handleKeydown)
})

/**
 * Static helper: check if warning should be shown or link opened directly.
 * Call this before opening the modal — if it returns false, open the URL directly.
 */
function shouldShowWarning(): boolean {
  return localStorage.getItem(STORAGE_KEY) !== 'true'
}

defineExpose({ shouldShowWarning })
</script>

<template>
  <Teleport :to="teleportTarget">
    <Transition name="dialog-fade">
      <div v-if="isOpen" class="fixed inset-0 z-[10000] flex items-center justify-center p-4">
        <!-- Backdrop: receives outside clicks (parent has no @click.self because child covers it) -->
        <div
          class="absolute inset-0 bg-black/50 dark:bg-black/70 backdrop-blur-sm"
          @click="cancel"
        ></div>

        <!-- Dialog -->
        <div
          class="relative surface-card rounded-xl shadow-2xl max-w-md w-full p-6 space-y-4 animate-scale-in"
          role="dialog"
          aria-modal="true"
          @click.stop
        >
          <!-- Header -->
          <div class="flex items-center gap-3">
            <div
              class="flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center bg-amber-500/10 text-amber-500"
            >
              <Icon icon="mdi:open-in-new" class="w-5 h-5" />
            </div>
            <div>
              <h3 class="text-lg font-semibold txt-primary">{{ t('externalLink.title') }}</h3>
              <p class="text-xs txt-secondary mt-0.5">{{ t('externalLink.subtitle') }}</p>
            </div>
          </div>

          <!-- URL display -->
          <div
            class="rounded-lg surface-chip px-3.5 py-2.5 flex items-center gap-2.5 overflow-hidden"
          >
            <Icon icon="mdi:web" class="w-4 h-4 text-blue-400 shrink-0" />
            <span class="text-sm txt-primary font-medium truncate">{{ hostname }}</span>
          </div>

          <!-- Disclaimer -->
          <p class="txt-secondary text-sm leading-relaxed">
            {{ t('externalLink.disclaimer') }}
          </p>

          <!-- Don't ask again -->
          <label class="flex items-center gap-2 cursor-pointer select-none group">
            <input
              v-model="dontAskAgain"
              type="checkbox"
              class="rounded text-brand focus:ring-brand/30 w-3.5 h-3.5"
            />
            <span class="text-xs txt-secondary group-hover:txt-primary transition-colors">
              {{ t('externalLink.dontAskAgain') }}
            </span>
          </label>

          <!-- Actions -->
          <div class="flex gap-3 justify-end pt-2">
            <button
              type="button"
              class="px-4 py-2 rounded-lg border border-light-border/30 dark:border-dark-border/20 txt-secondary hover:bg-black/5 dark:hover:bg-white/5 transition-all text-sm font-medium"
              @click="cancel"
            >
              {{ t('externalLink.cancel') }}
            </button>
            <button
              type="button"
              class="btn-primary px-4 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-1.5"
              @click="proceed"
            >
              <Icon icon="mdi:open-in-new" class="w-3.5 h-3.5" />
              {{ t('externalLink.open') }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.dialog-fade-enter-active,
.dialog-fade-leave-active {
  transition: opacity 0.2s ease;
}

.dialog-fade-enter-from,
.dialog-fade-leave-to {
  opacity: 0;
}

.animate-scale-in {
  animation: scale-in 0.2s ease-out;
}

@keyframes scale-in {
  from {
    opacity: 0;
    transform: scale(0.95);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}
</style>
