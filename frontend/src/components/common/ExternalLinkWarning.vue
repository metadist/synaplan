<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'

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
  <Teleport to="#app">
    <Transition name="fade">
      <div
        v-if="isOpen"
        class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
        @click.self="cancel"
      >
        <div
          class="w-full max-w-md rounded-2xl surface-primary shadow-2xl border border-light-border/10 dark:border-dark-border/10 overflow-hidden"
        >
          <!-- Header -->
          <div class="flex items-center gap-3 px-5 pt-5 pb-3">
            <div class="flex items-center justify-center w-10 h-10 rounded-full bg-amber-500/10">
              <Icon icon="mdi:open-in-new" class="w-5 h-5 text-amber-500" />
            </div>
            <div>
              <h3 class="text-base font-semibold txt-primary">{{ t('externalLink.title') }}</h3>
              <p class="text-xs txt-secondary mt-0.5">{{ t('externalLink.subtitle') }}</p>
            </div>
          </div>

          <!-- Body -->
          <div class="px-5 py-3 space-y-3">
            <div
              class="rounded-lg surface-chip px-3.5 py-2.5 flex items-center gap-2.5 overflow-hidden"
            >
              <Icon icon="mdi:web" class="w-4 h-4 text-blue-400 shrink-0" />
              <span class="text-sm txt-primary font-medium truncate">{{ hostname }}</span>
            </div>

            <p class="text-[13px] txt-secondary leading-relaxed">
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
          </div>

          <!-- Actions -->
          <div
            class="flex items-center justify-end gap-2 px-5 py-4 border-t border-light-border/10 dark:border-dark-border/10"
          >
            <button
              type="button"
              class="px-4 py-2 rounded-lg text-sm font-medium txt-secondary hover:surface-chip transition-colors"
              @click="cancel"
            >
              {{ t('externalLink.cancel') }}
            </button>
            <button
              type="button"
              class="px-4 py-2 rounded-lg text-sm font-medium bg-brand text-white hover:opacity-90 transition-opacity flex items-center gap-1.5"
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
