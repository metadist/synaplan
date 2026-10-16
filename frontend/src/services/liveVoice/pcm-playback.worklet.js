/* global AudioWorkletProcessor, sampleRate, registerProcessor */

/** Queue this much audio before the first sample, so a late chunk is a wait and not a click. */
export const PREBUFFER_SECONDS = 0.08

/**
 * A turn shorter than the prebuffer still plays, once this long passes with
 * nothing further arriving. GPT-Live has no "audio finished" event.
 */
export const START_STALL_SECONDS = 0.15

const FADE_SECONDS = 0.005
const IDLE_SECONDS = 0.3

/**
 * Gapless PCM16 playback. Chunks are one stream: the read position carries
 * across the boundary, and the last sample of a chunk interpolates against
 * the first sample of the next one.
 */
export class PcmStream {
  constructor() {
    this.samples = new Float32Array(0)
    this.fraction = 0
    this.sourceRate = 24000
    this.playing = false
    this.idleSent = false
    this.silence = 0
    this.levelSum = 0
    this.levelCount = 0
    this.started = false
    this.fadeIn = 0
    this.fadeOut = 1
    this.fadingOut = false
    this.lastSample = 0
    this.turnEnded = false
    this.stall = 0
    this.messages = []
  }

  config(sourceRate) {
    this.sourceRate = sourceRate > 0 ? sourceRate : 24000
  }

  push(incoming) {
    if (!incoming || incoming.length === 0) return
    const merged = new Float32Array(this.samples.length + incoming.length)
    merged.set(this.samples, 0)
    for (let n = 0; n < incoming.length; n += 1)
      merged[this.samples.length + n] = incoming[n] / 32768
    this.samples = merged
    this.silence = 0
    this.playing = true
    this.idleSent = false
    this.turnEnded = false
    this.stall = 0
  }

  endTurn() {
    this.turnEnded = true
  }

  flush() {
    this.samples = new Float32Array(0)
    this.fraction = 0
    this.silence = 0
    this.playing = false
    this.idleSent = false
    this.started = false
    this.fadeIn = 0
    this.fadeOut = 1
    this.fadingOut = false
    this.lastSample = 0
    this.turnEnded = false
    this.stall = 0
    this.levelSum = 0
    this.levelCount = 0
    this.messages.push({ type: 'level', level: 0 })
  }

  takeMessages() {
    const messages = this.messages
    this.messages = []
    return messages
  }

  buffered() {
    const left = this.samples.length - this.fraction
    return left > 0 ? left : 0
  }

  render(output, deviceRate) {
    const step = this.sourceRate / deviceRate
    const prebuffer = this.sourceRate * PREBUFFER_SECONDS
    const fadeSamples = Math.max(1, Math.round(deviceRate * FADE_SECONDS))
    let wrote = false

    for (let i = 0; i < output.length; i += 1) {
      if (this.fadingOut) {
        this.fadeOut = Math.max(0, this.fadeOut - 1 / fadeSamples)
        output[i] = this.lastSample * this.fadeOut
        if (this.fadeOut === 0) {
          this.fadingOut = false
          this.started = false
          this.lastSample = 0
          this.fadeIn = 0
        } else {
          wrote = true
        }
        continue
      }

      if (!this.started) {
        const buffered = this.buffered()
        this.stall = buffered > 0 ? this.stall + 1 : 0
        const stalled = this.stall >= deviceRate * START_STALL_SECONDS
        const ready = buffered >= prebuffer || (buffered > 0 && (this.turnEnded || stalled))
        if (!ready) {
          output[i] = 0
          continue
        }
        this.started = true
        this.fadeIn = 0
        this.stall = 0
      }

      const sample = this.read(step)
      if (sample === null) {
        this.fadingOut = true
        this.fadeOut = Math.max(0, 1 - 1 / fadeSamples)
        output[i] = this.lastSample * this.fadeOut
        if (this.fadeOut === 0) {
          this.fadingOut = false
          this.started = false
          this.lastSample = 0
        } else {
          wrote = true
        }
        continue
      }

      this.fadeIn = Math.min(1, this.fadeIn + 1 / fadeSamples)
      const shaped = sample * this.fadeIn
      output[i] = shaped
      this.lastSample = sample
      this.levelSum += shaped * shaped
      wrote = true
    }

    if (wrote) {
      this.levelCount += output.length
      if (this.levelCount >= deviceRate / 10) {
        const level = Math.min(1, Math.sqrt(this.levelSum / this.levelCount) * 4)
        this.messages.push({ type: 'level', level })
        this.levelSum = 0
        this.levelCount = 0
      }
      this.silence = 0
      return
    }

    if (this.playing && !this.idleSent && this.buffered() === 0 && !this.started) {
      this.silence += output.length
      if (this.silence > deviceRate * IDLE_SECONDS) {
        this.idleSent = true
        this.playing = false
        this.levelSum = 0
        this.levelCount = 0
        this.messages.push({ type: 'level', level: 0 })
        this.messages.push({ type: 'idle' })
      }
    }
  }

  read(step) {
    if (this.fraction >= this.samples.length) {
      this.samples = new Float32Array(0)
      this.fraction = 0
      return null
    }
    const index = Math.floor(this.fraction)
    const frac = this.fraction - index
    const next = index + 1 < this.samples.length ? this.samples[index + 1] : this.samples[index]
    const sample = this.samples[index] + (next - this.samples[index]) * frac
    this.fraction += step
    const drop = Math.floor(this.fraction) - 1
    if (drop > 2048 && drop < this.samples.length) {
      const rest = new Float32Array(this.samples.length - drop)
      rest.set(this.samples.subarray(drop))
      this.samples = rest
      this.fraction -= drop
    }
    return sample
  }
}

if (typeof AudioWorkletProcessor !== 'undefined' && typeof registerProcessor === 'function') {
  class PcmPlaybackProcessor extends AudioWorkletProcessor {
    constructor() {
      super()
      this.stream = new PcmStream()
      this.port.onmessage = (event) => {
        const data = event.data
        if (data?.type === 'config') this.stream.config(data.sourceRate)
        else if (data?.type === 'pcm' && data.pcm) this.stream.push(data.pcm)
        else if (data?.type === 'end') this.stream.endTurn()
        else if (data?.type === 'flush') {
          this.stream.flush()
          this.post()
        }
      }
    }

    post() {
      for (const message of this.stream.takeMessages()) this.port.postMessage(message)
    }

    process(_inputs, outputs) {
      const output = outputs[0]?.[0]
      if (!output) return true
      this.stream.render(output, sampleRate)
      this.post()
      return true
    }
  }

  registerProcessor('pcm-playback', PcmPlaybackProcessor)
}
