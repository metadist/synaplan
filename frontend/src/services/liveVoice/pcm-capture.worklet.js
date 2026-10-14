/* global AudioWorkletProcessor, sampleRate, registerProcessor */
class PcmCaptureProcessor extends AudioWorkletProcessor {
  constructor() {
    super()
    this.targetRate = 24000
    this.fraction = 0
    this.frame = []
    this.frameSize = 480
    this.port.onmessage = (event) => {
      if (event.data?.type === 'config') {
        this.targetRate = event.data.targetRate
        this.frameSize = Math.max(1, Math.round(this.targetRate * 0.02))
      }
    }
  }

  process(inputs) {
    const input = inputs[0]?.[0]
    if (!input || input.length === 0) return true
    const step = sampleRate / this.targetRate
    let index = this.fraction
    let sum = 0
    while (index < input.length) {
      const i = Math.floor(index)
      const next = Math.min(i + 1, input.length - 1)
      const frac = index - i
      const sample = input[i] * (1 - frac) + input[next] * frac
      this.frame.push(sample)
      sum += sample * sample
      if (this.frame.length >= this.frameSize) {
        const pcm = new Int16Array(this.frame.length)
        for (let n = 0; n < this.frame.length; n += 1) {
          const clamped = Math.max(-1, Math.min(1, this.frame[n]))
          pcm[n] = clamped < 0 ? clamped * 0x8000 : clamped * 0x7fff
        }
        const level = Math.min(1, Math.sqrt(sum / this.frame.length) * 4)
        this.port.postMessage({ type: 'pcm', pcm, level }, [pcm.buffer])
        this.frame = []
        sum = 0
      }
      index += step
    }
    this.fraction = index - input.length
    return true
  }
}

registerProcessor('pcm-capture', PcmCaptureProcessor)
