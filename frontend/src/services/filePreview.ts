import type { FileItem } from '@/services/filesService'

/**
 * Shared file-preview logic for both Files surfaces — the `Generated` grid
 * (`FilesGrid.vue`) and the `Browse` list (`FilesView.vue`). Centralising kind
 * detection, iconography, and the neutral surface treatment here keeps the two
 * surfaces consistent and avoids a divergent second implementation (#1499).
 */
export type PreviewKind =
  'image' | 'video' | 'audio' | 'text' | 'document' | 'pdf' | 'calendar' | 'unknown'

const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif', 'svg', 'bmp', 'avif']
const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v']
const AUDIO_EXTENSIONS = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'opus']
/** Plain-text types whose `text_preview` snippet reads naturally as a preview. */
const TEXT_EXTENSIONS = ['txt', 'md', 'markdown', 'csv', 'log', 'json', 'xml', 'yml', 'yaml']
const DOCUMENT_EXTENSIONS = [
  'doc',
  'docx',
  'xls',
  'xlsx',
  'ppt',
  'pptx',
  'odt',
  'ods',
  'odp',
  'odg',
  'odf',
  'rtf',
]

export const extensionOf = (name: string | undefined | null): string =>
  (name ?? '').split('.').pop()?.toLowerCase() ?? ''

/** Map a bare file extension to a preview kind. */
export const kindFromExtension = (ext: string): PreviewKind => {
  if (IMAGE_EXTENSIONS.includes(ext)) return 'image'
  if (VIDEO_EXTENSIONS.includes(ext)) return 'video'
  if (AUDIO_EXTENSIONS.includes(ext)) return 'audio'
  if ('pdf' === ext) return 'pdf'
  if ('ics' === ext) return 'calendar'
  if (TEXT_EXTENSIONS.includes(ext)) return 'text'
  if (DOCUMENT_EXTENSIONS.includes(ext)) return 'document'
  return 'unknown'
}

/**
 * Resolve the preview kind for a listed file. The filename extension is the
 * most specific signal (it separates `txt` from `pdf` from `docx`, which the
 * coarse `origin_kind` lumps together as `document`); the generated
 * `origin_kind`/`file_type` fields are the fallback for extension-less media.
 */
export const previewKindForFile = (file: FileItem): PreviewKind => {
  const byExtension = kindFromExtension(extensionOf(file.display_name || file.filename))
  if ('unknown' !== byExtension) return byExtension

  const originKind = file.origin_kind
  if (
    'image' === originKind ||
    'video' === originKind ||
    'audio' === originKind ||
    'calendar' === originKind
  ) {
    return originKind
  }

  const byType = kindFromExtension((file.file_type || '').toLowerCase())
  if ('unknown' !== byType) return byType

  return 'document' === originKind ? 'document' : 'unknown'
}

/** Iconify id for a preview kind (mdi set, consistent across both surfaces). */
export const previewIcon = (kind: PreviewKind): string => {
  switch (kind) {
    case 'image':
      return 'mdi:image-outline'
    case 'video':
      return 'mdi:play-circle-outline'
    case 'audio':
      return 'mdi:music-note'
    case 'pdf':
      return 'mdi:file-pdf-box'
    case 'calendar':
      return 'mdi:calendar'
    case 'text':
    case 'document':
      return 'mdi:file-document-outline'
    default:
      return 'mdi:file-outline'
  }
}

export const previewIconForFile = (file: FileItem): string => previewIcon(previewKindForFile(file))
export const previewIconForName = (name: string): string =>
  previewIcon(kindFromExtension(extensionOf(name)))

/**
 * Neutral, token-based surface for a type badge/icon — replaces the previous
 * per-extension Tailwind rainbow (`bg-red-500/10`, `bg-purple-500/10`, …) that
 * violated the styling convention and was visually noisy (#1499). One uniform
 * treatment, readable in light, dark, and V2.
 */
export const previewBadgeClass = (): string => 'bg-[var(--bg-chip)] txt-secondary'

/** Trimmed text snippet from the already-serialized `text_preview` field. */
export const previewSnippet = (file: FileItem): string => (file.text_preview ?? '').trim()

/** Whether a text-bearing tile should render a snippet instead of a bare icon. */
export const hasTextSnippet = (file: FileItem): boolean => previewSnippet(file).length > 0
