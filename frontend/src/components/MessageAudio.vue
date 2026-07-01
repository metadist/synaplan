<template>
  <div class="my-3" data-testid="section-message-audio">
    <div
      class="relative w-full surface-card overflow-hidden rounded-lg border border-light-border/30 dark:border-dark-border/20 p-4"
    >
      <!-- Error state: file could not be loaded (404, NotSupportedError, etc.) -->
      <div v-if="hasFailed" class="flex items-center gap-3" data-testid="audio-load-error">
        <div
          class="flex-shrink-0 w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-red-500/15 text-red-500 flex items-center justify-center"
          aria-hidden="true"
        >
          <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.74-3l-7.07-12.25a2 2 0 00-3.48 0L3.19 16a2 2 0 001.74 3z"
            />
          </svg>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium txt-primary">
            {{ $t('chat.audioUnavailable') }}
          </p>
          <p class="text-xs txt-secondary mt-0.5">
            {{ $t('chat.audioUnavailableDescription') }}
          </p>
        </div>
        <a
          v-if="canDownload"
          :href="props.url"
          :download="fileName"
          class="flex-shrink-0 txt-primary hover:text-[var(--brand)] transition-colors"
          :aria-label="$t('commands.download')"
          data-testid="btn-audio-download-fallback"
        >
          <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
            />
          </svg>
        </a>
      </div>

      <div v-else class="flex items-center gap-2 sm:gap-4">
        <!-- Play/Pause Button -->
        <button
          class="flex-shrink-0 w-10 h-10 sm:w-12 sm:h-12 rounded-full bg-[var(--brand)] text-white flex items-center justify-center hover:bg-[var(--brand)]/90 transition-all shadow-lg disabled:opacity-50 disabled:cursor-not-allowed"
          :aria-label="isPlaying ? 'Pause' : 'Play'"
          :disabled="isRetrying"
          data-testid="btn-audio-play"
          @click="togglePlay"
        >
          <svg
            v-if="isRetrying"
            class="w-5 h-5 sm:w-6 sm:h-6 animate-spin"
            fill="none"
            viewBox="0 0 24 24"
          >
            <circle
              class="opacity-25"
              cx="12"
              cy="12"
              r="10"
              stroke="currentColor"
              stroke-width="4"
            />
            <path
              class="opacity-75"
              fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
          </svg>
          <svg
            v-else-if="!isPlaying"
            class="w-5 h-5 sm:w-6 sm:h-6 ml-0.5 sm:ml-1"
            fill="currentColor"
            viewBox="0 0 24 24"
          >
            <path d="M8 5v14l11-7z" />
          </svg>
          <svg v-else class="w-5 h-5 sm:w-6 sm:h-6" fill="currentColor" viewBox="0 0 24 24">
            <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z" />
          </svg>
        </button>

        <!-- Audio Controls -->
        <div class="flex-1 min-w-0 space-y-1.5 sm:space-y-2">
          <!-- Progress Bar -->
          <div
            class="h-2 bg-black/10 dark:bg-white/10 rounded-full overflow-hidden cursor-pointer"
            @click="seek"
            @mousedown="startDragging"
          >
            <div
              class="h-full bg-[var(--brand)] transition-all"
              :style="{ width: `${progress}%` }"
            ></div>
          </div>

          <!-- Time Display -->
          <div class="flex items-center justify-between text-xs sm:text-sm txt-secondary gap-2">
            <span class="font-mono tabular-nums flex-shrink-0">{{ currentTime }}</span>
            <span class="font-mono tabular-nums flex-shrink-0">{{ duration }}</span>
          </div>
        </div>

        <!-- Volume Control -->
        <button
          class="flex-shrink-0 txt-primary hover:text-[var(--brand)] transition-colors"
          :aria-label="isMuted ? 'Unmute' : 'Mute'"
          data-testid="btn-audio-mute"
          @click="toggleMute"
        >
          <svg
            v-if="!isMuted"
            class="w-5 h-5 sm:w-6 sm:h-6"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"
            />
          </svg>
          <svg
            v-else
            class="w-5 h-5 sm:w-6 sm:h-6"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"
            />
          </svg>
        </button>

        <!-- Download Button -->
        <a
          :href="props.url"
          :download="fileName"
          class="flex-shrink-0 txt-primary hover:text-[var(--brand)] transition-colors"
          :aria-label="$t('commands.download')"
        >
          <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
            />
          </svg>
        </a>
      </div>

      <!-- Hidden Audio Element -->
      <audio
        v-if="!hasFailed"
        ref="audioRef"
        :src="audioSrc"
        class="hidden"
        preload="metadata"
        data-testid="media-audio-player"
        @timeupdate="updateProgress"
        @loadedmetadata="handleLoadSuccess"
        @ended="onEnded"
        @error="handleAudioError"
      ></audio>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onUnmounted } from 'vue'
import { useAudioPlayback } from '@/composables/useAudioPlayback'

interface Props {
  url: string
  poster?: string
  autoplay?: boolean
}

const props = defineProps<Props>()

// Only one inline audio player may play at a time (issue #1078): starting this
// one pauses any other that is currently playing.
const { setActive, clearActive } = useAudioPlayback()

const audioRef = ref<HTMLAudioElement>()
const isPlaying = ref(false)
const isMuted = ref(false)
const progress = ref(0)
const currentTimeSeconds = ref(0)
const durationSeconds = ref(0)
const isDragging = ref(false)
const hasAutoPlayed = ref(false)

// Issue #976: WhatsApp voice notes sometimes 404 on first load because the
// uploaded file has not yet propagated across the NFS-shared upload volume
// to the web tier. Retry with cache-busted URLs (same pattern as
// `MessageVideo.vue`) so a transient miss self-recovers instead of crashing
// the entire chat view via an unhandled `NotSupportedError` rejection from
// `<audio>.play()`.
const retryCount = ref(0)
const maxRetries = 3
const retryDelays = [1000, 2000, 3000]
const isRetrying = ref(false)
const hasFailed = ref(false)
const cacheBuster = ref(0)

const audioSrc = computed(() => {
  if (cacheBuster.value === 0) {
    return props.url
  }
  const separator = props.url.includes('?') ? '&' : '?'
  return `${props.url}${separator}_retry=${cacheBuster.value}`
})

const fileName = computed(() => {
  const parts = props.url.split('?')[0].split('/')
  return parts[parts.length - 1] || 'audio.mp3'
})

// Only offer the download fallback link when the source is fetchable as a
// file (avoids surfacing a broken `download` anchor on `data:` URLs or empty
// inputs, which are placeholders rather than real audio attachments).
const canDownload = computed(() => {
  const url = props.url?.trim() ?? ''
  if (!url) return false
  return !url.startsWith('data:')
})

const formatTime = (seconds: number): string => {
  if (!isFinite(seconds)) return '0:00'
  const mins = Math.floor(seconds / 60)
  const secs = Math.floor(seconds % 60)
  return `${mins}:${secs.toString().padStart(2, '0')}`
}

const currentTime = computed(() => formatTime(currentTimeSeconds.value))
const duration = computed(() => formatTime(durationSeconds.value))

// Pause this player when another one takes over the shared "playing" slot.
const stopSelf = (): void => {
  if (audioRef.value && !audioRef.value.paused) {
    audioRef.value.pause()
  }
  isPlaying.value = false
}

const handleAudioError = () => {
  if (retryCount.value < maxRetries) {
    isRetrying.value = true
    const delay = retryDelays[retryCount.value]
    console.warn(
      `Audio load failed, retrying in ${delay}ms (attempt ${retryCount.value + 1}/${maxRetries})`
    )

    setTimeout(() => {
      retryCount.value++
      cacheBuster.value = Date.now()
      // Force the <audio> element to re-fetch the (cache-busted) URL.
      if (audioRef.value) {
        audioRef.value.load()
      }
    }, delay)
  } else {
    // All retries exhausted — degrade to a non-crashing error state with a
    // download fallback so the user can still grab the file directly.
    isRetrying.value = false
    isPlaying.value = false
    hasFailed.value = true
    console.error('Audio failed to load after all retries:', props.url)
  }
}

const handleLoadSuccess = () => {
  isRetrying.value = false
  hasFailed.value = false
  if (!audioRef.value) return
  durationSeconds.value = audioRef.value.duration || 0

  // Autoplay for voice reply audio (only on first successful load).
  // Browser autoplay policies may block this; the catch keeps the rejection
  // from bubbling up to the global error handler and crashing the page.
  if (props.autoplay && !hasAutoPlayed.value) {
    hasAutoPlayed.value = true
    audioRef.value
      .play()
      .then(() => {
        isPlaying.value = true
        setActive(stopSelf)
      })
      .catch(() => {
        // Autoplay was blocked — the user can still click play manually.
      })
  }
}

const togglePlay = async () => {
  if (!audioRef.value || hasFailed.value || isRetrying.value) return

  if (isPlaying.value) {
    audioRef.value.pause()
    isPlaying.value = false
    clearActive()
    return
  }

  try {
    // `play()` returns a Promise that rejects on `NotSupportedError`
    // (404 / unsupported codec) or `NotAllowedError` (autoplay block).
    // Without an await + try/catch this becomes an unhandled rejection that
    // tears down the chat view through the global error handler — see #976.
    await audioRef.value.play()
    isPlaying.value = true
    setActive(stopSelf)
  } catch (err) {
    isPlaying.value = false
    // `NotSupportedError` indicates the source could not be loaded — escalate
    // to the load-error retry/fallback flow so the UI doesn't sit silently
    // in a broken state.
    const name = (err as DOMException)?.name
    if (name === 'NotSupportedError') {
      handleAudioError()
      return
    }
    console.warn('Audio playback failed:', err)
  }
}

const toggleMute = () => {
  if (!audioRef.value) return
  audioRef.value.muted = !audioRef.value.muted
  isMuted.value = audioRef.value.muted
}

const updateProgress = () => {
  if (!audioRef.value || isDragging.value) return
  currentTimeSeconds.value = audioRef.value.currentTime
  if (audioRef.value.duration) {
    progress.value = (audioRef.value.currentTime / audioRef.value.duration) * 100
  }
}

const seek = (event: MouseEvent) => {
  if (!audioRef.value || hasFailed.value) return
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const percent = (event.clientX - rect.left) / rect.width
  const total = audioRef.value.duration
  if (!isFinite(total) || total <= 0) return
  audioRef.value.currentTime = percent * total
  progress.value = percent * 100
  currentTimeSeconds.value = audioRef.value.currentTime
}

const startDragging = (event: MouseEvent) => {
  if (hasFailed.value) return
  isDragging.value = true
  seek(event)

  const handleMouseMove = (e: MouseEvent) => {
    if (isDragging.value) {
      seek(e)
    }
  }

  const handleMouseUp = () => {
    isDragging.value = false
    document.removeEventListener('mousemove', handleMouseMove)
    document.removeEventListener('mouseup', handleMouseUp)
  }

  document.addEventListener('mousemove', handleMouseMove)
  document.addEventListener('mouseup', handleMouseUp)
}

const onEnded = () => {
  isPlaying.value = false
  progress.value = 0
  currentTimeSeconds.value = 0
  clearActive()
}

onUnmounted(() => {
  if (audioRef.value && isPlaying.value) {
    audioRef.value.pause()
  }
})
</script>
