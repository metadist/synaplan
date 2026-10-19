<template>
  <div class="flex w-20 shrink-0 flex-col gap-1" data-testid="card-composer-media">
    <div class="relative h-20 w-20">
      <button
        type="button"
        class="relative h-full w-full overflow-hidden rounded-lg surface-chip focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] disabled:cursor-default"
        :aria-label="`${t('files.preview.open')}: ${name}`"
        :aria-busy="processing"
        :disabled="!displaySrc"
        data-testid="btn-composer-media"
        @click="open = true"
      >
        <img
          v-if="kind === 'image' && displaySrc && !failed"
          :src="displaySrc"
          :alt="name"
          class="h-full w-full object-cover"
          loading="lazy"
          data-testid="img-composer-media"
          @error="failed = true"
        />
        <video
          v-else-if="kind === 'video' && displaySrc && !failed"
          :src="displaySrc"
          class="pointer-events-none h-full w-full bg-black object-cover"
          muted
          playsinline
          preload="metadata"
          data-testid="media-composer-video"
          @loadedmetadata="holdFrame"
          @error="failed = true"
        />
        <span v-else class="flex h-full w-full items-center justify-center" aria-hidden="true">
          <PhotoIcon v-if="kind === 'image'" class="h-6 w-6 txt-secondary" />
          <FilmIcon v-else class="h-6 w-6 txt-secondary" />
        </span>

        <span
          v-if="kind === 'video'"
          class="pointer-events-none absolute inset-0 flex items-center justify-center"
          data-testid="badge-composer-video"
        >
          <span
            class="flex h-8 w-8 items-center justify-center rounded-full bg-black/60 text-white"
          >
            <PlayIcon class="h-4 w-4" aria-hidden="true" />
            <span class="sr-only">{{ t('files.preview.play') }}</span>
          </span>
        </span>

        <span
          v-if="processing"
          class="absolute inset-0 flex items-center justify-center bg-black/40"
        >
          <span class="sr-only">{{ t('common.loading') }}</span>
          <span
            class="h-5 w-5 animate-spin rounded-full border-2 border-white/40 border-t-white"
            aria-hidden="true"
          />
        </span>
      </button>

      <!-- Sits on the photo, so the ink is white on black rather than icon-ghost,
           which would inherit the composer color and disappear on a light image. -->
      <button
        type="button"
        class="absolute right-1 top-1 z-10 inline-flex h-7 w-7 items-center justify-center rounded-full bg-black/70 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white disabled:cursor-not-allowed disabled:opacity-40"
        :aria-label="t('files.removeFile')"
        :disabled="processing"
        data-testid="btn-remove-chat-file"
        @click.stop="emit('remove')"
      >
        <XMarkIcon class="h-4 w-4" />
      </button>
    </div>
    <p class="truncate text-xs txt-secondary" :title="name">{{ name }}</p>

    <Teleport to="body">
      <div
        v-if="open"
        class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/80 p-4"
        role="dialog"
        aria-modal="true"
        :aria-label="name"
        data-testid="modal-composer-media"
        @click="open = false"
      >
        <button
          ref="closeButton"
          type="button"
          class="absolute right-4 top-4 z-10 inline-flex h-11 w-11 items-center justify-center rounded-full bg-black/70 text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
          :aria-label="t('common.close')"
          data-testid="btn-composer-media-close"
          @click.stop="open = false"
        >
          <XMarkIcon class="h-6 w-6" />
        </button>
        <div class="flex max-h-full max-w-full flex-col items-center gap-3" @click.stop>
          <img
            v-if="kind === 'image' && displaySrc"
            :src="displaySrc"
            :alt="name"
            class="max-h-[70vh] max-w-full object-contain"
            data-testid="img-composer-media-large"
          />
          <video
            v-else-if="kind === 'video' && displaySrc"
            :src="displaySrc"
            class="max-h-[70vh] max-w-full bg-black"
            controls
            playsinline
            data-testid="media-composer-video-large"
          />
          <p class="max-w-full break-all text-center text-sm text-white">{{ name }}</p>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { FilmIcon, PhotoIcon, PlayIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { useMediaSrc } from '@/services/api/mediaAuth'

const props = defineProps<{
  name: string
  kind: 'image' | 'video'
  /** Blob URL while the file is still local, or the download path after upload. */
  src: string
  processing?: boolean
}>()

const emit = defineEmits<{ remove: [] }>()

const { t } = useI18n()
const { mediaSrc } = useMediaSrc()

const open = ref(false)
const failed = ref(false)
const closeButton = ref<HTMLButtonElement | null>(null)

const displaySrc = computed(() => mediaSrc(props.src))

watch(
  () => props.src,
  () => {
    failed.value = false
  }
)

const holdFrame = (event: Event) => {
  const video = event.target
  if (!(video instanceof HTMLVideoElement)) return
  // A media fragment on a blob URL makes Chrome reject the file, so the still
  // stays at the first frame and is only nudged forward when that is safe.
  if (video.currentTime < 0.05 && video.duration > 0.2) {
    try {
      video.currentTime = Math.min(0.1, video.duration / 2)
    } catch {
      // Keep the first frame the browser already painted.
    }
  }
  video.pause()
}

const onKeydown = (event: KeyboardEvent) => {
  if (event.key !== 'Escape' || !open.value) return
  event.preventDefault()
  open.value = false
}

watch(open, (isOpen) => {
  if (isOpen) {
    document.addEventListener('keydown', onKeydown)
    void nextTick(() => closeButton.value?.focus())
    return
  }
  document.removeEventListener('keydown', onKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', onKeydown)
})
</script>
