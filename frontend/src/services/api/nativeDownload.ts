/**
 * Cross-platform "save this blob" helper (Epic 7.1).
 *
 * MOBILE-APP SEAM: On the web we trigger the usual anchor download. Inside the
 * native shell an `<a download>` on a `blob:`/cross-origin URL does NOT reach
 * the system downloader, so the file would silently vanish. Persist the blob
 * via `@capacitor/filesystem` instead.
 *
 * Android writes into the public Documents folder (visible in the system Files
 * app) and stops there — a control labelled "Download" must not open the share
 * / send sheet. iOS has no equivalent public Downloads directory unless the
 * binary enables file sharing, so it still hands the file to the share sheet
 * ("Save to Files") and is unchanged.
 *
 * Keep ALL app file downloads going through `saveOrDownloadBlob` so web and
 * native stay in sync. (The embeddable widget is web-only and keeps its own
 * anchor download.)
 */
import { getNativePlatform, isNativeApp } from '@/services/api/nativeRuntime'

/** Persist/share a blob under `filename`, picking the right transport per platform. */
export async function saveOrDownloadBlob(blob: Blob, filename: string): Promise<void> {
  if (isNativeApp()) {
    await nativePersistBlob(blob, filename)
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

async function nativePersistBlob(blob: Blob, filename: string): Promise<void> {
  // Lazy-load native plugins so the web bundle never pulls them in.
  const { Filesystem, Directory } = await import('@capacitor/filesystem')
  const data = await blobToBase64(blob)
  const safeName = sanitizeFilename(filename)

  if ('android' === getNativePlatform()) {
    const path = await unusedDocumentsName(Filesystem, Directory, safeName)
    await Filesystem.writeFile({
      path,
      data,
      directory: Directory.Documents,
      recursive: true,
    })
    return
  }

  // iOS (and unknown native UAs): Cache + share sheet is the supported save path.
  const { Share } = await import('@capacitor/share')
  await Filesystem.writeFile({
    path: safeName,
    data,
    directory: Directory.Cache,
    recursive: true,
  })
  const { uri } = await Filesystem.getUri({ path: safeName, directory: Directory.Cache })
  await Share.share({ title: filename, url: uri })
}

type FilesystemPlugin = typeof import('@capacitor/filesystem').Filesystem
type DirectoryEnum = typeof import('@capacitor/filesystem').Directory

/**
 * Pick a Documents path that does not clobber a previous download of the same
 * name. `stat` resolving means the name is taken; any rejection means we can
 * use it (missing file is the common case).
 */
async function unusedDocumentsName(
  filesystem: FilesystemPlugin,
  directory: DirectoryEnum,
  safeName: string
): Promise<string> {
  for (let n = 0; n < 50; n += 1) {
    const candidate = 0 === n ? safeName : numberedFilename(safeName, n)
    try {
      await filesystem.stat({ path: candidate, directory: directory.Documents })
    } catch {
      return candidate
    }
  }
  return `${Date.now()}-${safeName}`
}

/** `photo.png` + 2 → `photo (2).png`; extension-less names just get the suffix. */
function numberedFilename(filename: string, n: number): string {
  const lastDot = filename.lastIndexOf('.')
  if (lastDot <= 0) {
    return `${filename} (${n})`
  }
  return `${filename.slice(0, lastDot)} (${n})${filename.slice(lastDot)}`
}

/** Strip path separators so a server-provided name can't escape the target dir. */
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
