/**
 * Cross-platform "save this blob" helper (Epic 7.1).
 *
 * On the web we trigger the usual anchor download. Inside the native shell an
 * `<a download>` on a `blob:`/cross-origin URL does NOT reach the system
 * downloader, so the file would silently vanish. Instead we persist the blob to
 * the app's cache directory via `@capacitor/filesystem` and hand it to the OS
 * share sheet via `@capacitor/share`, letting the user save it to Files/Photos,
 * send it on, etc.
 *
 * Keep ALL app file downloads going through `saveOrDownloadBlob` so web and
 * native stay in sync. (The embeddable widget is web-only and keeps its own
 * anchor download.)
 */
import { isNativeApp } from '@/services/api/nativeRuntime'

/** Persist/share a blob under `filename`, picking the right transport per platform. */
export async function saveOrDownloadBlob(blob: Blob, filename: string): Promise<void> {
  if (isNativeApp()) {
    await nativeSaveAndShare(blob, filename)
    return
  }
  webDownload(blob, filename)
}

function webDownload(blob: Blob, filename: string): void {
  const url = window.URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  window.URL.revokeObjectURL(url)
  document.body.removeChild(a)
}

async function nativeSaveAndShare(blob: Blob, filename: string): Promise<void> {
  // Lazy-load the native plugins so the web bundle never pulls them in.
  const [{ Filesystem, Directory }, { Share }] = await Promise.all([
    import('@capacitor/filesystem'),
    import('@capacitor/share'),
  ])

  const data = await blobToBase64(blob)
  const safeName = sanitizeFilename(filename)

  // Cache dir is app-private but shareable via Capacitor's FileProvider.
  await Filesystem.writeFile({
    path: safeName,
    data,
    directory: Directory.Cache,
    recursive: true,
  })

  const { uri } = await Filesystem.getUri({ path: safeName, directory: Directory.Cache })

  await Share.share({ title: filename, url: uri })
}

/** Strip path separators so a server-provided name can't escape the cache dir. */
function sanitizeFilename(filename: string): string {
  const base = filename.split(/[\\/]/).pop() ?? filename
  const trimmed = base.trim()
  return '' !== trimmed ? trimmed : 'download'
}

function blobToBase64(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onerror = () => {
      reject(reader.error ?? new Error('Failed to read blob'))
    }
    reader.onloadend = () => {
      const result = reader.result
      if ('string' !== typeof result) {
        reject(new Error('Unexpected FileReader result'))
        return
      }
      // Drop the `data:<mime>;base64,` prefix — Filesystem wants raw base64.
      const comma = result.indexOf(',')
      resolve(comma >= 0 ? result.slice(comma + 1) : result)
    }
    reader.readAsDataURL(blob)
  })
}
