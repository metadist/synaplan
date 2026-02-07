<template>
  <div class="my-3">
    <div
      class="relative w-full surface-card overflow-hidden rounded-lg border border-light-border/30 dark:border-dark-border/20 p-4"
    >
      <div class="flex items-center gap-4">
        <!-- Play/Pause Button -->
        <button
          class="flex-shrink-0 w-12 h-12 rounded-full bg-[var(--brand)] text-white flex items-center justify-center hover:bg-[var(--brand)]/90 transition-all shadow-lg"
          :aria-label="isPlaying ? 'Pause' : 'Play'"
          @click="togglePlay"
        >
          <svg v-if="!isPlaying" class="w-6 h-6 ml-1" fill="currentColor" viewBox="0 0 24 24">
            <path d="M8 5v14l11-7z" />
          </svg>
          <svg v-else class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
            <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z" />
          </svg>
        </button>

        <!-- Audio Controls -->
        <div class="flex-1 space-y-2">
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
          <div class="flex items-center justify-between text-sm txt-secondary">
            <span class="font-mono">{{ currentTime }}</span>
            <span class="font-mono">{{ duration }}</span>
          </div>
        </div>

        <!-- Volume Control -->
        <button
          class="flex-shrink-0 txt-primary hover:text-[var(--brand)] transition-colors"
          :aria-label="isMuted ? 'Unmute' : 'Mute'"
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

        <!-- Download Button -->
        <a
          :href="url"
          :download="fileName"
          class="flex-shrink-0 txt-primary hover:text-[var(--brand)] transition-colors"
          :aria-label="$t('commands.download')"
        >
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
        ref="audioRef"
        :src="url"
        class="hidden"
        @timeupdate="updateProgress"
        @loadedmetadata="updateDuration"
        @ended="onEnded"
      ></audio>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onUnmounted } from 'vue'

interface Props {
  url: string
  poster?: string
  autoplay?: boolean
}

const props = defineProps<Props>()

const audioRef = ref<HTMLAudioElement>()
const isPlaying = ref(false)
const isMuted = ref(false)
const progress = ref(0)
const currentTimeSeconds = ref(0)
const durationSeconds = ref(0)
const isDragging = ref(false)

const fileName = computed(() => {
  const parts = props.url.split('/')
  return parts[parts.length - 1] || 'audio.mp3'
})

const formatTime = (seconds: number): string => {
  if (!isFinite(seconds)) return '0:00'
  const mins = Math.floor(seconds / 60)
  const secs = Math.floor(seconds % 60)
  return `${mins}:${secs.toString().padStart(2, '0')}`
}

const currentTime = computed(() => formatTime(currentTimeSeconds.value))
const duration = computed(() => formatTime(durationSeconds.value))

const togglePlay = () => {
  if (!audioRef.value) return

  if (isPlaying.value) {
    audioRef.value.pause()
  } else {
    audioRef.value.play()
  }
  isPlaying.value = !isPlaying.value
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

const updateDuration = () => {
  if (!audioRef.value) return
  durationSeconds.value = audioRef.value.duration || 0

  // Autoplay for voice reply audio (only on first load)
  if (props.autoplay && !hasAutoPlayed.value) {
    hasAutoPlayed.value = true
    audioRef.value
      .play()
      .then(() => {
        isPlaying.value = true
      })
      .catch(() => {
        // Browser may block autoplay — user can click play manually
      })
  }
}

const hasAutoPlayed = ref(false)

const seek = (event: MouseEvent) => {
  if (!audioRef.value) return
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const percent = (event.clientX - rect.left) / rect.width
  audioRef.value.currentTime = percent * audioRef.value.duration
  progress.value = percent * 100
  currentTimeSeconds.value = audioRef.value.currentTime
}

const startDragging = (event: MouseEvent) => {
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
}

// Cleanup on unmount
onUnmounted(() => {
  if (audioRef.value && isPlaying.value) {
    audioRef.value.pause()
  }
})
</script>
