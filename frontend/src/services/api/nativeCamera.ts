/**
 * SPA-side seam for native photo capture (iOS Analyze-photo shortcut).
 *
 * The native shell exposes `window.SynaplanCamera`, which wraps
 * `@capacitor/camera` so this submodule stays Capacitor-free. On the plain
 * web build the bridge is absent and every accessor is a no-op.
 *
 * MOBILE-APP SEAM: default-off. Web file-input capture is unchanged.
 */

interface NativePhoto {
  dataUrl: string
  mimeType: string
  fileName: string
}

interface NativeCameraApi {
  isAvailable: () => boolean
  capturePhoto: () => Promise<NativePhoto | null>
}

function getApi(): NativeCameraApi | null {
  const api = (globalThis as { SynaplanCamera?: unknown }).SynaplanCamera
  if (
    api &&
    'object' === typeof api &&
    'function' === typeof (api as NativeCameraApi).isAvailable &&
    'function' === typeof (api as NativeCameraApi).capturePhoto
  ) {
    return api as NativeCameraApi
  }
  return null
}

/** True when the app-owned Camera bridge can present a capture UI. */
export function isNativeCameraAvailable(): boolean {
  const api = getApi()
  try {
    return !!api && true === api.isAvailable()
  } catch {
    return false
  }
}

function dataUrlToFile(photo: NativePhoto): File | null {
  const comma = photo.dataUrl.indexOf(',')
  const encoded = comma >= 0 ? photo.dataUrl.slice(comma + 1) : photo.dataUrl
  try {
    const binary = atob(encoded)
    const bytes = new Uint8Array(binary.length)
    for (let i = 0; i < binary.length; i += 1) {
      bytes[i] = binary.charCodeAt(i)
    }
    return new File([bytes], photo.fileName, { type: photo.mimeType || 'image/jpeg' })
  } catch {
    return null
  }
}

/**
 * Open the native camera (photo library on the Simulator) and return a File
 * ready for `ChatInput.uploadFiles()`. Resolves null on cancel, missing
 * bridge, or a failed capture — never throws.
 */
export async function captureNativePhoto(): Promise<File | null> {
  const api = getApi()
  if (!api) {
    return null
  }
  try {
    const photo = await api.capturePhoto()
    if (!photo || 'string' !== typeof photo.dataUrl || '' === photo.dataUrl) {
      return null
    }
    return dataUrlToFile({
      dataUrl: photo.dataUrl,
      mimeType: 'string' === typeof photo.mimeType ? photo.mimeType : 'image/jpeg',
      fileName: 'string' === typeof photo.fileName ? photo.fileName : 'photo.jpg',
    })
  } catch {
    return null
  }
}
