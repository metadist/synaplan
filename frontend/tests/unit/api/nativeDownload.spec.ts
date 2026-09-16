import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * MOBILE-APP SEAM (Epic 7.1): Download must save on Android and must not open
 * the share/send sheet. iOS keeps the share sheet because the binary does not
 * expose a public Documents folder via file sharing.
 */

const runtime = vi.hoisted(() => ({
  native: false,
  platform: 'web' as 'web' | 'ios' | 'android',
}))

const writeFile = vi.hoisted(() => vi.fn(async () => ({ uri: 'file://written' })))
const getUri = vi.hoisted(() => vi.fn(async () => ({ uri: 'file://cache/photo.png' })))
const stat = vi.hoisted(() => vi.fn(async () => ({ type: 'file', size: 1 })))
const share = vi.hoisted(() => vi.fn(async () => undefined))

vi.mock('@/services/api/nativeRuntime', () => ({
  isNativeApp: () => runtime.native,
  getNativePlatform: () => runtime.platform,
}))

vi.mock('@capacitor/filesystem', () => ({
  Directory: { Cache: 'CACHE', Documents: 'DOCUMENTS' },
  Filesystem: {
    writeFile,
    getUri,
    stat,
  },
}))

vi.mock('@capacitor/share', () => ({
  Share: { share },
}))

import { saveOrDownloadBlob } from '@/services/api/nativeDownload'

describe('saveOrDownloadBlob', () => {
  let createObjectURL: ReturnType<typeof vi.fn>
  let revokeObjectURL: ReturnType<typeof vi.fn>
  let click: ReturnType<typeof vi.fn>

  beforeEach(() => {
    runtime.native = false
    runtime.platform = 'web'
    writeFile.mockClear()
    getUri.mockClear()
    stat.mockClear()
    share.mockClear()
    stat.mockRejectedValue(new Error('not found'))

    createObjectURL = vi.fn(() => 'blob:test')
    revokeObjectURL = vi.fn()
    click = vi.fn()
    vi.stubGlobal('URL', {
      createObjectURL,
      revokeObjectURL,
    })
    vi.spyOn(document, 'createElement').mockImplementation((tag: string) => {
      if ('a' === tag) {
        return { href: '', download: '', click } as unknown as HTMLElement
      }
      return document.createElement(tag)
    })
    vi.spyOn(document.body, 'appendChild').mockImplementation((node) => node)
    vi.spyOn(document.body, 'removeChild').mockImplementation((node) => node)
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('uses an anchor download on the web', async () => {
    await saveOrDownloadBlob(new Blob(['hello']), 'notes.txt')

    expect(createObjectURL).toHaveBeenCalledOnce()
    expect(click).toHaveBeenCalledOnce()
    expect(writeFile).not.toHaveBeenCalled()
    expect(share).not.toHaveBeenCalled()
  })

  it('writes into Documents on Android and does not open the share sheet', async () => {
    runtime.native = true
    runtime.platform = 'android'

    await saveOrDownloadBlob(new Blob(['hello']), 'recording.webm')

    expect(writeFile).toHaveBeenCalledWith(
      expect.objectContaining({
        path: 'recording.webm',
        directory: 'DOCUMENTS',
        recursive: true,
      })
    )
    expect(share).not.toHaveBeenCalled()
    expect(getUri).not.toHaveBeenCalled()
  })

  it('picks a numbered Documents name when the file already exists', async () => {
    runtime.native = true
    runtime.platform = 'android'
    stat
      .mockResolvedValueOnce({ type: 'file', size: 1 })
      .mockRejectedValueOnce(new Error('not found'))

    await saveOrDownloadBlob(new Blob(['hello']), 'photo.png')

    expect(writeFile).toHaveBeenCalledWith(
      expect.objectContaining({
        path: 'photo (1).png',
        directory: 'DOCUMENTS',
      })
    )
    expect(share).not.toHaveBeenCalled()
  })

  it('keeps the iOS share-sheet save path', async () => {
    runtime.native = true
    runtime.platform = 'ios'

    await saveOrDownloadBlob(new Blob(['hello']), 'notes.txt')

    expect(writeFile).toHaveBeenCalledWith(
      expect.objectContaining({
        path: 'notes.txt',
        directory: 'CACHE',
      })
    )
    expect(share).toHaveBeenCalledWith({
      title: 'notes.txt',
      url: 'file://cache/photo.png',
    })
  })

  it('strips path separators from the stored name', async () => {
    runtime.native = true
    runtime.platform = 'android'

    await saveOrDownloadBlob(new Blob(['hello']), '../../evil/../secret.pdf')

    expect(writeFile).toHaveBeenCalledWith(
      expect.objectContaining({
        path: 'secret.pdf',
        directory: 'DOCUMENTS',
      })
    )
  })
})
