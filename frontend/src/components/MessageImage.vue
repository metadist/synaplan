<template>
  <div class="my-3" data-testid="section-message-image">
    <div
      class="relative w-full aspect-video surface-card overflow-hidden cursor-pointer group border border-light-border/30 dark:border-dark-border/20 hover:border-light-border/50 dark:hover:border-dark-border/30 transition-all"
      data-testid="btn-image-fullscreen"
      @click="openFullscreen"
    >
      <img
        v-if="blobUrl"
        :src="blobUrl"
        :alt="alt"
        class="w-full h-full object-cover transition-transform group-hover:scale-105"
        loading="lazy"
      />
      <div v-else class="w-full h-full flex items-center justify-center">
        <div class="text-sm txt-secondary">Loading image...</div>
      </div>
      <div
        class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center"
      >
        <div
          class="opacity-0 group-hover:opacity-100 transition-opacity surface-card p-3 rounded-full"
        >
          <svg class="w-6 h-6 txt-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"
            />
          </svg>
        </div>
      </div>
      <button
        v-if="blobUrl"
        class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity surface-card p-2 rounded-full txt-primary"
        :aria-label="$t('message.downloadImage')"
        :title="$t('message.downloadImage')"
        data-testid="btn-image-download"
        @click.stop="downloadImage"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"
          />
        </svg>
      </button>
    </div>
    <p v-if="alt" class="mt-2 text-sm txt-secondary">{{ alt }}</p>
  </div>

  <!-- Fullscreen Modal - Teleported to #app to overlay other app content -->
  <Teleport to="#app">
    <Transition
      enter-active-class="transition-opacity duration-300"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-opacity duration-200"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="isFullscreen"
        class="fixed inset-0 bg-black z-[9999] flex items-center justify-center p-4"
        data-testid="modal-image-fullscreen"
        @click="closeFullscreen"
      >
        <button
          v-if="blobUrl"
          class="absolute top-4 right-16 text-white/80 hover:text-white transition-colors p-2 z-10"
          :aria-label="$t('message.downloadImage')"
          :title="$t('message.downloadImage')"
          data-testid="btn-image-download-fullscreen"
          @click.stop="downloadImage"
        >
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"
            />
          </svg>
        </button>
        <button
          class="absolute top-4 right-4 text-white/80 hover:text-white transition-colors p-2 z-10"
          :aria-label="$t('common.close')"
          data-testid="btn-image-close"
          @click.stop="closeFullscreen"
        >
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M6 18L18 6M6 6l12 12"
            />
          </svg>
        </button>
        <img
          v-if="blobUrl"
          :src="blobUrl"
          :alt="alt"
          class="max-w-full max-h-full object-contain z-10"
          @click.stop
        />
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useConfigStore } from '@/stores/config'

interface Props {
  url: string
  alt?: string
}

const props = defineProps<Props>()
const config = useConfigStore()

const isFullscreen = ref(false)
const blobUrl = ref<string>('')

// Load image with auth header
const loadImage = async () => {
  try {
    // External URLs (e.g. from OpenAI) don't need auth
    if (props.url.startsWith('http://') || props.url.startsWith('https://')) {
      blobUrl.value = props.url
      return
    }

    // Internal API URLs need authentication via cookies
    const fullUrl = props.url.startsWith('/') ? `${config.appBaseUrl}${props.url}` : props.url

    const response = await fetch(fullUrl, {
      method: 'GET',
      credentials: 'include',
    })

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`)
    }

    // Convert response to blob
    const blob = await response.blob()
    blobUrl.value = URL.createObjectURL(blob)
  } catch (error) {
    console.error('Failed to load image:', error)
  }
}

// Derive a sensible filename from the source URL, falling back to image.png
// when the URL carries no usable name (e.g. a query-only blob endpoint).
const downloadFilename = (): string => {
  const path = props.url.split('?')[0].split('#')[0]
  const name = path.substring(path.lastIndexOf('/') + 1)
  return name && name.includes('.') ? name : 'image.png'
}

// Internal images load as blob: URLs (authenticated via cookies), so native
// "Save image as…" fails. Trigger a real download from the already-loaded blob;
// for external/not-yet-loaded URLs, fetch a fresh blob so the download still
// produces a valid file (issue #1071).
const downloadImage = async () => {
  let tempUrl: string | null = null
  try {
    let href = blobUrl.value

    if (!href || !href.startsWith('blob:')) {
      const fullUrl = props.url.startsWith('/') ? `${config.appBaseUrl}${props.url}` : props.url
      const response = await fetch(fullUrl, { method: 'GET', credentials: 'include' })
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`)
      }
      const blob = await response.blob()
      tempUrl = URL.createObjectURL(blob)
      href = tempUrl
    }

    const link = document.createElement('a')
    link.href = href
    link.download = downloadFilename()
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
  } catch (error) {
    console.error('Failed to download image:', error)
  } finally {
    if (tempUrl) {
      URL.revokeObjectURL(tempUrl)
    }
  }
}

const openFullscreen = () => {
  isFullscreen.value = true
}

const closeFullscreen = () => {
  isFullscreen.value = false
}

const handleEscape = (e: KeyboardEvent) => {
  if (e.key === 'Escape' && isFullscreen.value) {
    closeFullscreen()
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleEscape)
  loadImage()
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleEscape)
  // Clean up blob URL
  if (blobUrl.value && blobUrl.value.startsWith('blob:')) {
    URL.revokeObjectURL(blobUrl.value)
  }
})

// Reload image if URL changes
watch(
  () => props.url,
  () => {
    if (blobUrl.value && blobUrl.value.startsWith('blob:')) {
      URL.revokeObjectURL(blobUrl.value)
    }
    loadImage()
  }
)
</script>
