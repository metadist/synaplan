<template>
  <div class="my-3" data-testid="section-message-video">
    <div class="relative w-full aspect-video surface-card overflow-hidden border border-light-border/30 dark:border-dark-border/20 group">
      <video
        ref="videoRef"
        :src="url"
        :poster="poster"
        class="w-full h-full bg-black"
        preload="metadata"
        @click="togglePlay"
        data-testid="media-video-player"
      >
        {{ $t('commands.videoNotSupported') }}
      </video>
      
      <!-- Custom Controls -->
      <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 via-black/50 to-transparent p-4 opacity-0 group-hover:opacity-100 transition-opacity">
        <div class="flex items-center gap-3">
          <button
            @click="togglePlay"
            class="text-white hover:text-white/80 transition-colors"
            :aria-label="isPlaying ? 'Pause' : 'Play'"
            data-testid="btn-video-play"
          >
            <svg v-if="!isPlaying" class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
              <path d="M8 5v14l11-7z"/>
            </svg>
            <svg v-else class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24">
              <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"/>
            </svg>
          </button>
          
          <div class="flex-1 h-1 bg-white/30 rounded-full overflow-hidden cursor-pointer" @click="seek">
            <div 
              class="h-full bg-[var(--brand)] transition-all"
              :style="{ width: `${progress}%` }"
            ></div>
          </div>
          
          <span class="text-white text-sm font-mono">{{ currentTime }} / {{ duration }}</span>
          
          <button
            @click="toggleMute"
            class="text-white hover:text-white/80 transition-colors"
            :aria-label="isMuted ? 'Unmute' : 'Mute'"
            data-testid="btn-video-mute"
          >
            <svg v-if="!isMuted" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z" />
            </svg>
            <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z M17 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2" />
            </svg>
          </button>
          
          <button
            @click="toggleFullscreen"
            class="text-white hover:text-white/80 transition-colors"
            aria-label="Fullscreen"
            data-testid="btn-video-fullscreen"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
            </svg>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

interface Props {
  url: string
  poster?: string
}

defineProps<Props>()

const videoRef = ref<HTMLVideoElement | null>(null)
const isPlaying = ref(false)
const isMuted = ref(false)
const progress = ref(0)
const currentTime = ref('0:00')
const duration = ref('0:00')

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

onMounted(() => {
  if (videoRef.value) {
    videoRef.value.addEventListener('timeupdate', updateProgress)
    videoRef.value.addEventListener('loadedmetadata', updateDuration)
    videoRef.value.addEventListener('ended', () => {
      isPlaying.value = false
    })
  }
})

onUnmounted(() => {
  if (videoRef.value) {
    videoRef.value.removeEventListener('timeupdate', updateProgress)
    videoRef.value.removeEventListener('loadedmetadata', updateDuration)
  }
})
</script>
