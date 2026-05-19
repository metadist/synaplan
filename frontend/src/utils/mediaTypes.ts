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

const AUDIO_EXTENSIONS = new Set(['ogg', 'mp3', 'wav', 'm4a', 'opus', 'flac', 'webm', 'amr', 'aac'])

const IMAGE_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'svg'])

const VIDEO_EXTENSIONS = new Set(['mp4', 'mov', 'avi', 'mkv'])

function normalize(type: string | null | undefined): string {
  return (type ?? '').trim().toLowerCase()
}

/**
 * True for both the generic `audio` media kind and any concrete audio
 * file extension the backend may have stored (`ogg`, `mp3`, …).
 *
 * `webm` is intentionally in the audio set because WhatsApp/MediaRecorder
 * uploads use it for voice notes. `MessageAudio` plays it correctly via
 * `<audio>` — the duplicate entry in `VIDEO_EXTENSIONS` would only matter
 * if we tried to auto-classify a `.webm` *without* any other hint, which
 * the chat history path never does (it always has an explicit media kind
 * available too).
 */
export function isAudioFileType(type: string | null | undefined): boolean {
  const normalized = normalize(type)
  if (!normalized) return false
  if (normalized === 'audio') return true
  return AUDIO_EXTENSIONS.has(normalized)
}

export function isImageFileType(type: string | null | undefined): boolean {
  const normalized = normalize(type)
  if (!normalized) return false
  if (normalized === 'image') return true
  return IMAGE_EXTENSIONS.has(normalized)
}

export function isVideoFileType(type: string | null | undefined): boolean {
  const normalized = normalize(type)
  if (!normalized) return false
  if (normalized === 'video') return true
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
