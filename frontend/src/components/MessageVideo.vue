<template>
  <div class="my-3" data-testid="section-message-video">
    <div
      class="relative w-full aspect-video surface-card overflow-hidden border border-light-border/30 dark:border-dark-border/20 group"
    >
      <!-- Loading/Retry indicator -->
      <div
        v-if="isRetrying || (!hasFailed && !videoSrc)"
        class="absolute inset-0 flex items-center justify-center bg-black/50 z-10"
      >
        <div class="text-white text-sm flex items-center gap-2">
          <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle
              class="opacity-25"
              cx="12"
              cy="12"
              r="10"
              stroke="currentColor"
              stroke-width="4"
            ></circle>
            <path
              class="opacity-75"
              fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            ></path>
          </svg>
          {{ $t('common.loading') }}
        </div>
      </div>

      <!-- Error state: all retries exhausted (404, expired credential, codec) -->
      <div
        v-if="hasFailed"
        class="w-full h-full flex flex-col items-center justify-center gap-2 p-4 text-center"
        data-testid="video-load-error"
      >
        <svg
          class="w-8 h-8 text-red-500"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 9v2m0 4h.01M4.93 19h14.14a2 2 0 001.74-3l-7.07-12.25a2 2 0 00-3.48 0L3.19 16a2 2 0 001.74 3z"
          />
        </svg>
        <p class="text-sm font-medium txt-primary">{{ $t('chat.videoUnavailable') }}</p>
        <p class="text-xs txt-secondary">{{ $t('chat.videoUnavailableDescription') }}</p>
        <button
          type="button"
          class="btn-secondary text-xs px-3 py-1"
          data-testid="btn-video-retry"
          @click="retryManually"
        >
          {{ $t('common.retry') }}
        </button>
      </div>

      <video
        v-else-if="videoSrc"
        ref="videoRef"
        :src="videoSrc"
        :poster="posterSrc"
        class="w-full h-full bg-black"
        preload="metadata"
        data-testid="media-video-player"
        @click="togglePlay"
        @error="handleVideoError"
        @loadedmetadata="handleLoadSuccess"
        @timeupdate="updateProgress"
        @ended="onEnded"
      >
        {{ $t('commands.videoNotSupported') }}
      </video>

      <!-- Custom Controls -->
      <div
        v-if="!hasFailed && videoSrc"
        class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 via-black/50 to-transparent p-4 opacity-0 group-hover:opacity-100 transition-opacity"
      >
        <div class="flex items-center gap-3">
          <button
            class="text-white hover:text-white/80 transition-colors"
            :aria-label="isPlaying ? 'Pause' : 'Play'"
            data-testid="btn-video-play"
            @click="togglePlay"
          >
            <svg v-if="!isPlaying" class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
              <path d="M8 5v14l11-7z" />
            </svg>
            <svg v-else class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
              <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z" />
            </svg>
          </button>

          <div
            class="flex-1 h-1 bg-white/30 rounded-full overflow-hidden cursor-pointer"
            @click="seek"
          >
            <div
              class="h-full bg-[var(--brand)] transition-all"
              :style="{ width: `${progress}%` }"
            ></div>
          </div>

          <span class="text-white text-sm font-mono">{{ currentTime }} / {{ duration }}</span>

          <button
            class="text-white hover:text-white/80 transition-colors"
            :aria-label="isMuted ? 'Unmute' : 'Mute'"
            data-testid="btn-video-mute"
            @click="toggleMute"
          >
            <svg
              v-if="!isMuted"
              class="w-6 h-6"
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
            <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2"
              />
            </svg>
          </button>

          <button
            class="text-white hover:text-white/80 transition-colors"
            aria-label="Fullscreen"
            data-testid="btn-video-fullscreen"
            @click="toggleFullscreen"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"
              />
            </svg>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import { useMediaSrc } from '@/services/api/mediaAuth'

interface Props {
  url: string
  poster?: string
  autoplay?: boolean
}

const props = defineProps<Props>()

// No-op on web; on native this resolves the URL against the configured server
// and appends a read-only media token (falling back to `?token=` only on
// servers that predate that endpoint) because <video> can't send auth headers.
// `reloadMedia()` mints a fresh credential for the retry path.
const { mediaSrc, reloadMedia } = useMediaSrc()

const videoRef = ref<HTMLVideoElement | null>(null)
const isPlaying = ref(false)
const isMuted = ref(false)
const progress = ref(0)
const currentTime = ref('0:00')
const duration = ref('0:00')

// NFS retry logic - newly created files may take a moment to propagate across servers
const retryCount = ref(0)
const maxRetries = 3
const retryDelays = [1000, 2000, 3000] // Increasing delays: 1s, 2s, 3s
const isRetrying = ref(false)
const hasFailed = ref(false)
const hasAutoPlayed = ref(false)

const videoSrc = computed(() => mediaSrc(props.url))
const posterSrc = computed(() => (props.poster ? mediaSrc(props.poster) : undefined))

// The <video> element reports a rejected credential and a missing file the
// same way, so every retry mints a fresh token as well as busting the cache.
const reloadSource = async () => {
  await reloadMedia()
  await nextTick()
  videoRef.value?.load()
}

const handleVideoError = () => {
  if (retryCount.value >= maxRetries) {
    isRetrying.value = false
    hasFailed.value = true
    console.error('Video failed to load after all retries:', props.url)
    return
  }

  isRetrying.value = true
  const delay = retryDelays[retryCount.value]
  console.warn(
    `Video load failed, retrying in ${delay}ms (attempt ${retryCount.value + 1}/${maxRetries})`
  )

  setTimeout(() => {
    retryCount.value++
    void reloadSource()
  }, delay)
}

const retryManually = async () => {
  retryCount.value = 0
  hasFailed.value = false
  isRetrying.value = true
  await reloadSource()
}

const handleLoadSuccess = () => {
  isRetrying.value = false
  hasFailed.value = false
  updateDuration()

  // Lazy-mounted grid tiles pass `autoplay` so a single tap on the poster both
  // mounts the player and starts playback (#1499). Browser autoplay policies may
  // reject this; the catch keeps the rejection from bubbling to the global
  // handler (mirrors MessageAudio) — the user can still press play.
  if (props.autoplay && !hasAutoPlayed.value && videoRef.value) {
    hasAutoPlayed.value = true
    videoRef.value
      .play()
      .then(() => {
        isPlaying.value = true
      })
      .catch(() => {
        // Autoplay blocked — the poster/controls remain; manual play works.
      })
  }
}

const formatTime = (seconds: number): string => {
  const mins = Math.floor(seconds / 60)
  const secs = Math.floor(seconds % 60)
  return `${mins}:${secs.toString().padStart(2, '0')}`
}

const updateProgress = () => {
  if (videoRef.value) {
    const percent = (videoRef.value.currentTime / videoRef.value.duration) * 100
    progress.value = percent || 0
    currentTime.value = formatTime(videoRef.value.currentTime)
  }
}

const updateDuration = () => {
  if (videoRef.value && videoRef.value.duration) {
    duration.value = formatTime(videoRef.value.duration)
  }
}

const togglePlay = () => {
  if (videoRef.value) {
    if (videoRef.value.paused) {
      videoRef.value.play()
      isPlaying.value = true
    } else {
      videoRef.value.pause()
      isPlaying.value = false
    }
  }
}

const toggleMute = () => {
  if (videoRef.value) {
    videoRef.value.muted = !videoRef.value.muted
    isMuted.value = videoRef.value.muted
  }
}

const toggleFullscreen = () => {
  if (videoRef.value) {
    if (document.fullscreenElement) {
      document.exitFullscreen()
    } else {
      videoRef.value.requestFullscreen()
    }
  }
}

const seek = (e: MouseEvent) => {
  if (videoRef.value) {
    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
    const percent = (e.clientX - rect.left) / rect.width
    videoRef.value.currentTime = percent * videoRef.value.duration
  }
}

const onEnded = () => {
  isPlaying.value = false
}
</script>
