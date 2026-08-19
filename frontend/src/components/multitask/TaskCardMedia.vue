<template>
  <div class="mt-2" data-testid="task-card-media">
    <!-- Image: click → lightbox, hover → download -->
    <div v-if="kind === 'image'" class="relative inline-block group">
      <img
        v-if="mediaSrc"
        :src="mediaSrc"
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
      <video v-if="mediaSrc" :src="mediaSrc" controls class="rounded-lg max-h-72 w-auto" />
      <DownloadLink @download="download" />
    </template>
    <template v-else-if="kind === 'audio'">
      <audio v-if="mediaSrc" :src="mediaSrc" controls class="w-full" />
      <DownloadLink @download="download" />
    </template>

    <!-- Documents: same authenticated blob download as the other kinds — a
         bare anchor to a cookie-authed API URL could save an HTML error page -->
    <DownloadLink v-else @download="download" />
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
          v-if="mediaSrc"
          :src="mediaSrc"
          :alt="$t('taskPlan.kind.image')"
          class="max-w-full max-h-full object-contain z-10"
          @click.stop
        />
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, defineComponent, h, onMounted, onUnmounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { downloadMediaUrl, resolveMediaUrl, useMediaSrc } from '@/services/api/mediaAuth'

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

// No-op on web; on native it resolves the URL against the configured server
// and appends a read-only media token because media elements can't send
// auth headers (MOBILE-APP SEAM, see mediaAuth.ts).
const { mediaSrc: buildMediaSrc } = useMediaSrc()
const mediaSrc = computed(() => buildMediaSrc(props.url))

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
// downloadMediaUrl authenticates per platform (cookie / Bearer) and saves via
// anchor on web or Filesystem + Share in the native shell.
const download = async () => {
  try {
    await downloadMediaUrl(resolveMediaUrl(props.url), downloadFilename())
  } catch (error) {
    console.error('Failed to download task media:', error)
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
