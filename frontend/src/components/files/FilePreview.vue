<template>
  <div
    class="w-full aspect-video overflow-hidden bg-[var(--bg-chip)] relative flex items-center justify-center"
    data-testid="file-preview"
  >
    <!-- Image: inline thumbnail (unchanged behavior) -->
    <img
      v-if="kind === 'image'"
      :src="imageSrc"
      :alt="displayName"
      class="w-full h-full object-cover transition-transform group-hover:scale-105"
      loading="lazy"
      data-testid="file-preview-image"
    />

    <!-- Video: lazy player — poster + play overlay by default, real player on demand -->
    <template v-else-if="kind === 'video'">
      <MessageVideo
        v-if="playing"
        :url="rawDownloadUrl"
        :poster="rawThumbUrl ?? undefined"
        autoplay
        class="!my-0 w-full h-full"
        data-testid="file-preview-video-player"
      />
      <button
        v-else
        type="button"
        class="group/play absolute inset-0 w-full h-full flex items-center justify-center"
        :aria-label="t('files.preview.play')"
        data-testid="file-preview-video-play"
        @click="emit('play')"
      >
        <img
          v-if="posterSrc"
          :src="posterSrc"
          :alt="displayName"
          class="absolute inset-0 w-full h-full object-cover"
          loading="lazy"
        />
        <span
          class="relative z-10 w-12 h-12 rounded-full bg-black/50 backdrop-blur-sm flex items-center justify-center text-white transition-transform group-hover/play:scale-110"
        >
          <Icon icon="mdi:play" class="w-6 h-6 ml-0.5" />
        </span>
      </button>
    </template>

    <!-- Audio: lazy player — icon + filename + compact play control by default -->
    <template v-else-if="kind === 'audio'">
      <div v-if="playing" class="w-full px-2">
        <MessageAudio
          :url="rawDownloadUrl"
          autoplay
          class="!my-0 w-full"
          data-testid="file-preview-audio-player"
        />
      </div>
      <button
        v-else
        type="button"
        class="group/play flex flex-col items-center justify-center gap-2 w-full h-full px-3"
        :aria-label="t('files.preview.play')"
        data-testid="file-preview-audio-play"
        @click="emit('play')"
      >
        <Icon :icon="icon" class="w-9 h-9 txt-secondary" />
        <span class="text-xs txt-secondary truncate max-w-full" :title="displayName">
          {{ displayName }}
        </span>
        <span
          class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-[var(--brand)]/10 text-[var(--brand)] text-[11px] font-medium transition-colors group-hover/play:bg-[var(--brand)]/20"
        >
          <Icon icon="mdi:play" class="w-3.5 h-3.5" />
          {{ t('files.preview.play') }}
        </span>
      </button>
    </template>

    <!-- Text / document with an extracted snippet -->
    <div
      v-else-if="snippet"
      class="w-full h-full p-3 overflow-hidden text-left flex flex-col gap-1.5"
      data-testid="file-preview-snippet"
    >
      <Icon :icon="icon" class="w-5 h-5 txt-secondary shrink-0" />
      <p class="text-[11px] leading-snug txt-secondary whitespace-pre-wrap preview-clamp">
        {{ snippet }}
      </p>
    </div>

    <!-- Icon-only fallback (documents without text, pdf, calendar, unknown) -->
    <Icon v-else :icon="icon" class="w-10 h-10 txt-secondary" data-testid="file-preview-icon" />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import MessageVideo from '@/components/MessageVideo.vue'
import MessageAudio from '@/components/MessageAudio.vue'
import type { FileItem } from '@/services/filesService'
import { previewIconForFile, previewKindForFile, previewSnippet } from '@/services/filePreview'
import { getApiBaseUrl } from '@/services/api/httpClient'
import { useMediaSrc } from '@/services/api/mediaAuth'
import { fileDisplayName } from '@/utils/fileDisplayName'

const props = defineProps<{
  file: FileItem
  /** True while this tile owns the single active player (mounts the real element). */
  playing?: boolean
}>()

const emit = defineEmits<{ play: [] }>()

const { t, locale } = useI18n()

// On web `mediaSrc` is a no-op; on native it appends a read-only media token so
// a bare <img> (which cannot send auth headers) still loads. MessageVideo /
// MessageAudio receive the RAW url and wrap it themselves.
const { mediaSrc } = useMediaSrc()

const kind = computed(() => previewKindForFile(props.file))
const icon = computed(() => previewIconForFile(props.file))
const snippet = computed(() => previewSnippet(props.file))
const displayName = computed(() =>
  fileDisplayName(props.file, (key, values) => t(key, (values ?? {}) as never), locale.value)
)

const rawDownloadUrl = computed(() => `${getApiBaseUrl()}/api/v1/files/${props.file.id}/download`)
// Build the absolute thumb URL from the id (mirrors the download URL) so it
// works when the API origin differs from the page origin (dev: :8000 vs :5173).
// Gated on the backend having recorded a thumbnail (thumb_url present).
const rawThumbUrl = computed(() =>
  props.file.thumb_url ? `${getApiBaseUrl()}/api/v1/files/${props.file.id}/thumb` : null
)

const imageSrc = computed(() => mediaSrc(rawDownloadUrl.value))
const posterSrc = computed(() => (rawThumbUrl.value ? mediaSrc(rawThumbUrl.value) : null))
</script>

<style scoped>
/* Clamp the text snippet to a few lines without an extra dependency. */
.preview-clamp {
  display: -webkit-box;
  -webkit-line-clamp: 4;
  line-clamp: 4;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
