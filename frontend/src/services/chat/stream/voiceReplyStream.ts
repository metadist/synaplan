/**
 * Speaks a streamed answer sentence by sentence (voice reply). Text inside
 * `<think>` blocks is never spoken, and the voice follows the reply language
 * once the classifier names it. The audio streamer is read on every call: Stop
 * and the streamer's own end both drop it while the answer still streams.
 */
import type { AudioStreamer } from '@/utils/AudioStreamer'

const SENTENCE_END = /([.?!]+)(\s+|$)|(\n+)/

export class VoiceReplyStream {
  private heard = ''
  private spokenLength = 0
  private insideThink = false

  constructor(
    private readonly streamer: () => AudioStreamer | null,
    private readonly onStopped: () => void,
    private language: string
  ) {}

  setLanguage(language: string): void {
    this.language = language
  }

  /** Answer text the streamer has heard, `<think>` blocks removed. */
  heardText(): string {
    return this.heard
  }

  feed(chunk: string): void {
    const streamer = this.streamer()
    if (!streamer) return

    this.heard += this.withoutThinking(chunk)
    for (;;) {
      const unspoken = this.heard.slice(this.spokenLength)
      const boundary = unspoken.match(SENTENCE_END)
      if (!boundary || boundary.index === undefined) break

      const end = boundary.index + boundary[0].length
      const sentence = unspoken.substring(0, end)
      if (sentence.trim()) {
        streamer.streamText(sentence, undefined, this.language)
      }
      this.spokenLength += end
    }
  }

  finish(): void {
    const streamer = this.streamer()
    if (!streamer) return

    const remaining = this.heard.slice(this.spokenLength)
    if (remaining.trim()) {
      streamer.streamText(remaining, undefined, this.language)
    }
    streamer.markComplete()
  }

  stop(): void {
    const streamer = this.streamer()
    if (!streamer) return

    streamer.stop()
    this.onStopped()
  }

  private withoutThinking(chunk: string): string {
    let audible = chunk.replace(/<think>[\s\S]*?<\/think>/gi, '')
    if (this.insideThink) {
      const close = audible.match(/<\/think>/i)
      if (!close || close.index === undefined) return ''
      audible = audible.substring(close.index + close[0].length)
      this.insideThink = false
    }
    const open = audible.match(/<think>/i)
    if (open && open.index !== undefined) {
      audible = audible.substring(0, open.index)
      this.insideThink = true
    }

    return audible
  }
}
