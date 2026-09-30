/**
 * AudioStreamer — plays TTS audio sentence-by-sentence with minimal latency.
 *
 * Strategy: Each sentence becomes an independent fetch to /api/v1/tts/stream,
 * returning a complete audio/webm blob. We create an <audio> element per blob
 * and chain them sequentially (when one ends, the next starts).
 *
 * This avoids MSE complexity and works reliably in all browsers.
 */
export type ReadAloudFailureReason = 'missing_language' | 'provider_error'

export class AudioStreamer {
  private queue: Array<{ text: string; language: string }> = []
  private isPlaying = false
  private stopped = false
  private failed = false
  private currentAudio: HTMLAudioElement | null = null
  private prefetchedBlobs: Map<number, string> = new Map() // index → blob URL
  private playIndex = 0
  private _allQueued = false
  private onFinished?: () => void
  private onFailure?: (reason: ReadAloudFailureReason) => void

  /**
   * Register a callback invoked once when all queued audio has finished playing
   * or when {@link stop} is called. The callback is one-shot: it fires at most once.
   */
  public setOnFinished(cb: () => void): void {
    this.onFinished = cb
  }

  /**
   * Register a callback invoked once when reading aloud has to stop: a chunk
   * fetch failed (#2282) or no language was given (#2283). Playback for this
   * message ends and no further chunks are fetched.
   */
  public setOnFailure(cb: (reason: ReadAloudFailureReason) => void): void {
    this.onFailure = cb
  }

  /**
   * Signal that no more sentences will be queued (text streaming is done).
   * Once the remaining queue drains, the onFinished callback fires.
   */
  public markComplete(): void {
    this._allQueued = true
    this.checkFinished()
  }

  public get active(): boolean {
    return !this.stopped && !this.failed && (this.isPlaying || this.queue.length > this.playIndex)
  }

  /**
   * Queue a sentence for TTS playback.
   * Starts prefetching immediately; playback begins as soon as first blob is ready.
   * Language is required — never invent English (#2283).
   */
  public streamText(text: string, _voice?: string, language?: string): void {
    if (this.stopped || this.failed) return
    const trimmed = text.trim()
    if (!trimmed) return

    const lang = (language ?? '').trim()
    if (!lang) {
      console.warn('AudioStreamer: language is required; stopping playback')
      this.failChunk('missing_language')
      return
    }

    this.queue.push({ text: trimmed, language: lang })
    const idx = this.queue.length - 1

    // Prefetch audio blob in background
    this.prefetch(idx)
  }

  private async prefetch(idx: number): Promise<void> {
    const item = this.queue[idx]
    if (!item || this.stopped || this.failed) return

    const params = new URLSearchParams({ text: item.text, language: item.language })

    try {
      const response = await fetch(`/api/v1/tts/stream?${params.toString()}`, {
        credentials: 'include', // Cookie-based auth
      })

      if (this.stopped || this.failed) return

      if (!response.ok) {
        console.warn(`AudioStreamer: TTS fetch failed (${response.status}) for idx ${idx}`)
        this.failChunk('provider_error')
        return
      }

      const blob = await response.blob()
      if (this.stopped || this.failed) return

      const blobUrl = URL.createObjectURL(blob)
      this.prefetchedBlobs.set(idx, blobUrl)

      // Start playback if nothing is playing yet
      if (!this.isPlaying) {
        this.tryPlayNext()
      }
    } catch (e) {
      if (!this.stopped && !this.failed) {
        console.warn('AudioStreamer: Prefetch error', e)
        this.failChunk('provider_error')
      }
    }
  }

  /**
   * Terminal failure: stop further fetches, end playback, notify.
   */
  private failChunk(reason: ReadAloudFailureReason): void {
    if (this.failed || this.stopped) return
    this.failed = true
    this.stopped = true
    if (this.currentAudio) {
      this.currentAudio.pause()
      this.currentAudio = null
    }
    for (const [, url] of this.prefetchedBlobs) {
      if (url) URL.revokeObjectURL(url)
    }
    this.prefetchedBlobs.clear()
    this.queue = []
    this.isPlaying = false
    const cb = this.onFailure
    this.onFailure = undefined
    cb?.(reason)
    this.fireFinished()
  }

  private tryPlayNext(): void {
    if (this.stopped || this.failed || this.isPlaying) return

    const blobUrl = this.prefetchedBlobs.get(this.playIndex)
    if (blobUrl === undefined) return // Not yet fetched

    // Empty string should not occur after failChunk — treat as skip just in case
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
      this.checkFinished()
    })

    audio.addEventListener('error', () => {
      URL.revokeObjectURL(blobUrl)
      this.prefetchedBlobs.delete(this.playIndex)
      this.isPlaying = false
      this.currentAudio = null
      this.failChunk('provider_error')
    })

    audio.play().catch((e) => {
      console.warn('AudioStreamer: Auto-play prevented', e)
      this.isPlaying = false
      this.currentAudio = null
      this.playIndex++
      this.tryPlayNext()
      this.checkFinished()
    })
  }

  public stop(): void {
    this.stopped = true
    if (this.currentAudio) {
      this.currentAudio.pause()
      this.currentAudio = null
    }
    for (const [, url] of this.prefetchedBlobs) {
      if (url) URL.revokeObjectURL(url)
    }
    this.prefetchedBlobs.clear()
    this.queue = []
    this.isPlaying = false
    this.fireFinished()
  }

  /** One-shot: fires the callback at most once, then clears it. */
  private fireFinished(): void {
    const cb = this.onFinished
    this.onFinished = undefined
    cb?.()
  }

  private checkFinished(): void {
    if (this._allQueued && !this.isPlaying && this.playIndex >= this.queue.length) {
      this.fireFinished()
    }
  }
}
