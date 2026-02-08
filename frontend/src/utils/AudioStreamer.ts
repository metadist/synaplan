/**
 * AudioStreamer — plays TTS audio sentence-by-sentence with minimal latency.
 *
 * Strategy: Each sentence becomes an independent fetch to /api/v1/tts/stream,
 * returning a complete audio/webm blob. We create an <audio> element per blob
 * and chain them sequentially (when one ends, the next starts).
 *
 * This avoids MSE complexity and works reliably in all browsers.
 */
export class AudioStreamer {
  private queue: Array<{ text: string, language?: string }> = []
  private isPlaying = false
  private stopped = false
  private currentAudio: HTMLAudioElement | null = null
  private prefetchedBlobs: Map<number, string> = new Map() // index → blob URL
  private nextIndex = 0
  private playIndex = 0

  /**
   * Queue a sentence for TTS playback.
   * Starts prefetching immediately; playback begins as soon as first blob is ready.
   */
  public streamText(text: string, _voice?: string, language?: string): void {
    if (this.stopped) return
    const trimmed = text.trim()
    if (!trimmed) return

    this.queue.push({ text: trimmed, language })
    const idx = this.queue.length - 1

    // Prefetch audio blob in background
    this.prefetch(idx)
  }

  private async prefetch(idx: number): Promise<void> {
    const item = this.queue[idx]
    if (!item || this.stopped) return

    const params = new URLSearchParams({ text: item.text })
    if (item.language) params.append('language', item.language)

    try {
      const response = await fetch(`/api/v1/tts/stream?${params.toString()}`, {
        credentials: 'include', // Cookie-based auth
      })

      if (!response.ok) {
        console.warn(`AudioStreamer: TTS fetch failed (${response.status}) for idx ${idx}`)
        // Skip this segment, try to play next
        this.prefetchedBlobs.set(idx, '')
        this.tryPlayNext()
        return
      }

      const blob = await response.blob()
      const blobUrl = URL.createObjectURL(blob)
      this.prefetchedBlobs.set(idx, blobUrl)

      // Start playback if nothing is playing yet
      if (!this.isPlaying) {
        this.tryPlayNext()
      }
    } catch (e) {
      if (!this.stopped) {
        console.warn('AudioStreamer: Prefetch error', e)
      }
      this.prefetchedBlobs.set(idx, '')
      this.tryPlayNext()
    }
  }

  private tryPlayNext(): void {
    if (this.stopped || this.isPlaying) return

    const blobUrl = this.prefetchedBlobs.get(this.playIndex)
    if (blobUrl === undefined) return // Not yet fetched

    // Empty string means fetch failed — skip
    if (!blobUrl) {
      this.playIndex++
      this.tryPlayNext()
      return
    }

    this.isPlaying = true
    const audio = new Audio(blobUrl)
    this.currentAudio = audio

    audio.addEventListener('ended', () => {
      URL.revokeObjectURL(blobUrl)
      this.prefetchedBlobs.delete(this.playIndex)
      this.isPlaying = false
      this.currentAudio = null
      this.playIndex++
      this.tryPlayNext()
    })

    audio.addEventListener('error', () => {
      URL.revokeObjectURL(blobUrl)
      this.prefetchedBlobs.delete(this.playIndex)
      this.isPlaying = false
      this.currentAudio = null
      this.playIndex++
      this.tryPlayNext()
    })

    audio.play().catch((e) => {
      console.warn('AudioStreamer: Auto-play prevented', e)
      this.isPlaying = false
      this.currentAudio = null
    })
  }

  public stop(): void {
    this.stopped = true
    if (this.currentAudio) {
      this.currentAudio.pause()
      this.currentAudio = null
    }
    // Clean up blob URLs
    for (const [, url] of this.prefetchedBlobs) {
      if (url) URL.revokeObjectURL(url)
    }
    this.prefetchedBlobs.clear()
    this.queue = []
    this.isPlaying = false
  }
}
