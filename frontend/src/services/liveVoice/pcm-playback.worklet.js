/* global AudioWorkletProcessor, registerProcessor */
class PcmPlaybackProcessor extends AudioWorkletProcessor {
  constructor() {
    super()
    this.queue = []
    this.sourceRate = 24000
    this.fraction = 0
    this.silence = 0
    this.playing = false
    this.idleSent = false
    this.levelSum = 0
    this.levelCount = 0
    this.port.onmessage = (event) => {
      if (event.data?.type === 'config') {
        this.sourceRate = event.data.sourceRate
      } else if (event.data?.type === 'pcm' && event.data.pcm) {
        const incoming = event.data.pcm
        const copy = new Float32Array(incoming.length)
        for (let n = 0; n < incoming.length; n += 1) copy[n] = incoming[n] / 32768
        this.queue.push(copy)
        this.silence = 0
        this.playing = true
        this.idleSent = false
      } else if (event.data?.type === 'flush') {
        this.queue = []
        this.fraction = 0
        this.silence = 0
        this.playing = false
        this.idleSent = false
        this.levelSum = 0
        this.levelCount = 0
        this.port.postMessage({ type: 'level', level: 0 })
      }
    }
  }

  process(_inputs, outputs) {
    const output = outputs[0]?.[0]
    if (!output) return true
    const step = this.sourceRate / sampleRate
    let wrote = false
    for (let i = 0; i < output.length; i += 1) {
      if (this.queue.length === 0) {
        output[i] = 0
        continue
      }
      const current = this.queue[0]
      const index = Math.floor(this.fraction)
      if (index >= current.length) {
        this.queue.shift()
        this.fraction = 0
        output[i] = 0
        continue
      }
      const nextIndex = Math.min(index + 1, current.length - 1)
      const frac = this.fraction - index
      const sample = current[index] + (current[nextIndex] - current[index]) * frac
      output[i] = sample
      this.levelSum += sample * sample
      wrote = true
      this.fraction += step
    }
    if (wrote) {
      this.levelCount += output.length
      if (this.levelCount >= sampleRate / 10) {
        const level = Math.min(1, Math.sqrt(this.levelSum / this.levelCount) * 4)
        this.port.postMessage({ type: 'level', level })
        this.levelSum = 0
        this.levelCount = 0
      }
      this.silence = 0
      return true
    }
    if (this.playing && !this.idleSent) {
      this.silence += output.length
      if (this.silence > sampleRate * 0.3) {
        this.idleSent = true
        this.playing = false
        this.levelSum = 0
        this.levelCount = 0
        this.port.postMessage({ type: 'level', level: 0 })
        this.port.postMessage({ type: 'idle' })
      }
    }
    return true
  }
}

registerProcessor('pcm-playback', PcmPlaybackProcessor)
