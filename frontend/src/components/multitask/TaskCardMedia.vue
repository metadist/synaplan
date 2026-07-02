<template>
  <div class="mt-2" data-testid="task-card-media">
    <!-- Image: click → lightbox, hover → download -->
    <div v-if="kind === 'image'" class="relative inline-block group">
      <img
        :src="url"
        :alt="$t('taskPlan.kind.image')"
        class="rounded-lg max-h-72 w-auto cursor-zoom-in"
        data-testid="task-card-image"
        @click="lightboxOpen = true"
      />
      <button
        type="button"
        class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity surface-card p-2 rounded-full txt-primary"
        :aria-label="$t('taskPlan.download')"
        :title="$t('taskPlan.download')"
        data-testid="task-card-media-download"
        @click.stop="download"
      >
        <Icon icon="mdi:download" class="w-4 h-4" />
      </button>
    </div>

    <!-- Video / audio players with a download affordance -->
    <template v-else-if="kind === 'video'">
      <video :src="url" controls class="rounded-lg max-h-72 w-auto" />
      <DownloadLink @download="download" />
    </template>
    <template v-else-if="kind === 'audio'">
      <audio :src="url" controls class="w-full" />
      <DownloadLink @download="download" />
    </template>

    <!-- Documents keep the plain download link -->
    <a
      v-else
      :href="url"
      target="_blank"
      rel="noopener"
      class="inline-flex items-center gap-2 text-sm"
      style="color: var(--brand)"
    >
      <Icon icon="mdi:download" class="w-4 h-4" />
      {{ $t('taskPlan.download') }}
    </a>
  </div>

  <!-- Image lightbox (Escape / click to close) — the MessageImage pattern -->
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
        v-if="lightboxOpen"
        class="fixed inset-0 bg-black z-[9999] flex items-center justify-center p-4"
        data-testid="task-card-lightbox"
        @click="lightboxOpen = false"
      >
        <button
          class="absolute top-4 right-16 text-white/80 hover:text-white transition-colors p-2 z-10"
          :aria-label="$t('taskPlan.download')"
          :title="$t('taskPlan.download')"
          @click.stop="download"
        >
          <Icon icon="mdi:download" class="w-7 h-7" />
        </button>
        <button
          class="absolute top-4 right-4 text-white/80 hover:text-white transition-colors p-2 z-10"
          :aria-label="$t('common.close')"
          data-testid="task-card-lightbox-close"
          @click.stop="lightboxOpen = false"
        >
          <Icon icon="mdi:close" class="w-7 h-7" />
        </button>
        <img
          :src="url"
          :alt="$t('taskPlan.kind.image')"
          class="max-w-full max-h-full object-contain z-10"
          @click.stop
        />
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { defineComponent, h, onMounted, onUnmounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'

/**
 * Media body of a completed task card (#1229): image with lightbox +
 * download, video/audio players with download, document download link.
 * Split out of TaskCard.vue to keep both components small.
 */
const props = defineProps<{
  /** Card kind: image | video | audio | document */
  kind: string
  /** Absolute (normalized) media URL */
  url: string
}>()

const { t } = useI18n()
const lightboxOpen = ref(false)

// Small inline helper so video/audio share one download affordance without a
// third component file.
const DownloadLink = defineComponent({
  emits: ['download'],
  setup(_, { emit }) {
    return () =>
      h(
        'button',
        {
          type: 'button',
          class: 'mt-1 inline-flex items-center gap-1 text-xs',
          style: 'color: var(--brand)',
          'data-testid': 'task-card-media-download',
          onClick: () => emit('download'),
        },
        [h(Icon, { icon: 'mdi:download', class: 'w-3.5 h-3.5' }), t('taskPlan.download')]
      )
  },
})

const downloadFilename = (): string => {
  const path = props.url.split('?')[0].split('#')[0]
  const name = path.substring(path.lastIndexOf('/') + 1)
  return name && name.includes('.') ? name : 'download'
}

// Authenticated blob download (the MessageImage pattern, issue #1071): a bare
// anchor to an API URL would save an HTML error page for cookie-authed media.
const download = async () => {
  let tempUrl: string | null = null
  try {
    const response = await fetch(props.url, { method: 'GET', credentials: 'include' })
    if (!response.ok) throw new Error(`HTTP ${response.status}`)
    tempUrl = URL.createObjectURL(await response.blob())

    const link = document.createElement('a')
    link.href = tempUrl
    link.download = downloadFilename()
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
  } catch (error) {
    console.error('Failed to download task media:', error)
  } finally {
    if (tempUrl) URL.revokeObjectURL(tempUrl)
  }
}

const handleEscape = (e: KeyboardEvent) => {
  if (e.key === 'Escape' && lightboxOpen.value) {
    lightboxOpen.value = false
  }
}

onMounted(() => window.addEventListener('keydown', handleEscape))
onUnmounted(() => window.removeEventListener('keydown', handleEscape))
</script>
