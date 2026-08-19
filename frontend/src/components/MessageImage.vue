<template>
  <div class="my-3" data-testid="section-message-image">
    <div
      class="relative w-full aspect-video surface-card overflow-hidden cursor-pointer group border border-light-border/30 dark:border-dark-border/20 hover:border-light-border/50 dark:hover:border-dark-border/30 transition-all"
      data-testid="btn-image-fullscreen"
      @click="openFullscreen"
    >
      <img
        v-if="imageSrc && !hasFailed"
        :src="imageSrc"
        :alt="alt"
        class="w-full h-full object-cover transition-transform group-hover:scale-105"
        loading="lazy"
        @load="onLoaded"
        @error="onError"
      />
      <div
        v-if="hasFailed"
        class="w-full h-full flex flex-col items-center justify-center gap-2 p-4 text-center"
        data-testid="image-load-error"
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
        <p class="text-sm font-medium txt-primary">{{ $t('chat.imageUnavailable') }}</p>
        <p class="text-xs txt-secondary">{{ $t('chat.imageUnavailableDescription') }}</p>
        <button
          type="button"
          class="btn-secondary text-xs px-3 py-1 relative z-10"
          data-testid="btn-image-retry"
          @click.stop="retryManually"
        >
          {{ $t('common.retry') }}
        </button>
      </div>
      <div
        v-else-if="!isLoaded"
        class="absolute inset-0 flex items-center justify-center pointer-events-none"
      >
        <div class="text-sm txt-secondary">{{ $t('common.loading') }}</div>
      </div>
      <div
        class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center pointer-events-none"
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
        v-if="!hasFailed"
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
          v-if="!hasFailed"
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
          v-if="imageSrc && !hasFailed"
          :src="imageSrc"
          :alt="alt"
          class="max-w-full max-h-full object-contain z-10"
          @click.stop
        />
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted, watch } from 'vue'
import { fetchMediaBlob, resolveMediaUrl, useMediaSrc } from '@/services/api/mediaAuth'
import { saveOrDownloadBlob } from '@/services/api/nativeDownload'

interface Props {
  url: string
  alt?: string
}

const props = defineProps<Props>()

const { mediaSrc, reloadMedia } = useMediaSrc()

const isFullscreen = ref(false)
const isLoaded = ref(false)
const hasFailed = ref(false)
const hasRetried = ref(false)

// A plain `src` instead of a blob: the browser caches it, `loading="lazy"`
// actually defers the request, and no decoded copy is pinned in JS memory.
// On native the URL carries a purpose-scoped media credential (see mediaAuth).
const imageSrc = computed(() => mediaSrc(props.url))

const onLoaded = () => {
  isLoaded.value = true
  hasFailed.value = false
  // A later error (token aged out after a successful paint) must get its own
  // silent credential refresh — otherwise the first retry "uses up" the
  // allowance for the whole lifetime of this element.
  hasRetried.value = false
}

// The most common cause of a rejected media URL is an aged-out credential, so
// mint a fresh one and let the element try again before blaming the server.
const onError = async () => {
  if (hasRetried.value) {
    hasFailed.value = true
    return
  }
  hasRetried.value = true
  await reloadMedia()
}

const retryManually = async () => {
  hasFailed.value = false
  hasRetried.value = false
  await reloadMedia()
}

// Derive a sensible filename from the source URL, falling back to image.png
// when the URL carries no usable name (e.g. a query-only blob endpoint).
const downloadFilename = (): string => {
  const path = props.url.split('?')[0].split('#')[0]
  const name = path.substring(path.lastIndexOf('/') + 1)
  return name && name.includes('.') ? name : 'image.png'
}

// Downloads always fetch their own blob (issue #1071): the displayed image is
// a plain `src`, so there is nothing in JS to reuse, and the request goes out
// with `Authorization: Bearer` rather than a credential in a URL. Saving goes
// through saveOrDownloadBlob: web keeps the anchor download, the native shell
// persists via Filesystem + Share because an `<a download>` click is a silent
// no-op inside the WebView.
const downloadImage = async () => {
  try {
    const blob = await fetchMediaBlob(resolveMediaUrl(props.url))
    await saveOrDownloadBlob(blob, downloadFilename())
  } catch (error) {
    console.error('Failed to download image:', error)
  }
}

const openFullscreen = () => {
  if (hasFailed.value || !imageSrc.value) {
    return
  }
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
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleEscape)
})

watch(
  () => props.url,
  () => {
    isLoaded.value = false
    hasFailed.value = false
    hasRetried.value = false
  }
)
</script>
