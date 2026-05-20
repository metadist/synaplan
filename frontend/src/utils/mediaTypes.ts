/**
 * Issue #955 — central place to decide whether a chat attachment or
 * generated-media payload represents an audio/image/video file.
 *
 * Background
 * ----------
 * The backend stores `BFILETYPE` either as a generic media kind
 * (`audio`, `image`, `video` — used by the TTS / MEDIAMAKER pipelines)
 * or as the raw file extension (`ogg`, `mp3`, `png`, … — used for
 * inbound WhatsApp voice messages and direct user uploads).
 *
 * Before this helper existed, `history.ts` only rendered an `<audio>`
 * player when `file.type === 'audio'`, which silently broke for any
 * WhatsApp voice note or chat upload whose `BFILETYPE` was still the
 * extension. The same gap meant attachments on `MessageFile.fileType`
 * — which are always extensions — never got rendered as a player at
 * all, only as a download badge.
 *
 * Keep this module pure and side-effect free so it can be unit-tested
 * in isolation and reused safely from anywhere in the chat UI.
 */

// `webm` lives in BOTH the audio and video sets because the container
// format is genuinely ambiguous (audio/webm voice notes vs. video/webm
// recordings). Callers are expected to pass the MIME type alongside the
// extension whenever they have it; the extension-only fallback resolves
// `.webm` to audio because voice notes are by far the dominant chat
// payload in this app, and `MessageAudio` plays them correctly via the
// `<audio>` element.
const AUDIO_EXTENSIONS = new Set(['ogg', 'mp3', 'wav', 'm4a', 'opus', 'flac', 'webm', 'amr', 'aac'])

const IMAGE_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg'])

const VIDEO_EXTENSIONS = new Set(['mp4', 'mov', 'avi', 'mkv', 'webm'])

function normalize(type: string | null | undefined): string {
  return (type ?? '').trim().toLowerCase()
}

/**
 * Returns the major part of a MIME type (e.g. `audio/webm` → `audio`)
 * or an empty string when the input is missing or malformed. Trimmed
 * and lowercased to match the rest of this module.
 */
function mimeMajor(mime: string | null | undefined): string {
  const normalized = (mime ?? '').trim().toLowerCase()
  if (!normalized) return ''
  const slash = normalized.indexOf('/')
  return slash > 0 ? normalized.slice(0, slash) : normalized
}

/**
 * True for both the generic `audio` media kind and any concrete audio
 * file extension the backend may have stored (`ogg`, `mp3`, …).
 *
 * Pass the `mime` argument whenever the attachment carries a MIME type
 * (it does on `MessageFile.fileMime`). The MIME type is authoritative
 * and disambiguates `webm` between audio (voice notes) and video.
 */
export function isAudioFileType(
  type: string | null | undefined,
  mime?: string | null | undefined
): boolean {
  const major = mimeMajor(mime)
  if ('audio' === major) return true
  if ('video' === major || 'image' === major) return false

  const normalized = normalize(type)
  if (!normalized) return false
  if ('audio' === normalized) return true
  return AUDIO_EXTENSIONS.has(normalized)
}

export function isImageFileType(
  type: string | null | undefined,
  mime?: string | null | undefined
): boolean {
  const major = mimeMajor(mime)
  if ('image' === major) return true
  if ('audio' === major || 'video' === major) return false

  const normalized = normalize(type)
  if (!normalized) return false
  if ('image' === normalized) return true
  return IMAGE_EXTENSIONS.has(normalized)
}

export function isVideoFileType(
  type: string | null | undefined,
  mime?: string | null | undefined
): boolean {
  const major = mimeMajor(mime)
  if ('video' === major) return true
  if ('audio' === major || 'image' === major) return false

  const normalized = normalize(type)
  if (!normalized) return false
  if ('video' === normalized) return true
  // Ambiguous extensions (webm) default to audio — see VIDEO_EXTENSIONS
  // comment above. Without a MIME hint we cannot tell the formats apart,
  // so the explicit `video` generic kind or a non-webm extension is
  // required to classify as video here.
  if ('webm' === normalized) return false
  return VIDEO_EXTENSIONS.has(normalized)
}

/**
 * Build the static-serve URL for an uploaded file by relative path.
 *
 * The backend exposes uploaded chat attachments under
 * `/api/v1/files/uploads/{relativePath}` (see `StaticUploadController`).
 * Cookie-based session auth means a plain `<audio src="…">` tag can
 * fetch it directly — no manual blob/Authorization plumbing required.
 *
 * Returns an empty string for falsy input so the caller can safely
 * `if (!url) return` without extra null checks.
 */
export function buildUploadUrl(relativePath: string | null | undefined): string {
  const path = (relativePath ?? '').trim()
  if (!path) return ''
  if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('data:')) {
    return path
  }
  if (path.startsWith('/api/v1/files/uploads/') || path.startsWith('/up/')) {
    return path
  }
  return `/api/v1/files/uploads/${path.replace(/^\/+/, '')}`
}
