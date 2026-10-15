import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { classicWorkletSource, PcmFrameAssembler } from '@/services/liveVoice/audio'
import {
  PcmStream,
  PREBUFFER_SECONDS,
  START_STALL_SECONDS,
} from '@/services/liveVoice/pcm-playback.worklet.js'

const SOURCE_RATE = 24_000

function render(stream: PcmStream, length: number, deviceRate: number): Float32Array {
  const out = new Float32Array(length)
  const block = 128
  for (let offset = 0; offset < out.length; offset += block) {
    stream.render(out.subarray(offset, Math.min(out.length, offset + block)), deviceRate)
  }
  return out
}

function play(chunks: Int16Array[], deviceRate: number): Float32Array {
  const stream = new PcmStream()
  stream.config(SOURCE_RATE)
  for (const chunk of chunks) stream.push(chunk)
  const sourceLength = chunks.reduce((total, chunk) => total + chunk.length, 0)
  const length = Math.ceil((sourceLength * deviceRate) / SOURCE_RATE) + Math.ceil(deviceRate * 0.02)
  return render(stream, length, deviceRate)
}

function chunksOf(pcm: Int16Array, size: number): Int16Array[] {
  const chunks: Int16Array[] = []
  for (let offset = 0; offset < pcm.length; offset += size) {
    chunks.push(pcm.subarray(offset, offset + size))
  }
  return chunks
}

function ramp(length: number): Int16Array {
  const pcm = new Int16Array(length)
  for (let i = 0; i < length; i += 1) pcm[i] = 1000 + (i % 50) * 300
  return pcm
}

function sameSamples(left: Float32Array, right: Float32Array): void {
  expect(left.length).toBe(right.length)
  for (let i = 0; i < left.length; i += 1) {
    if (left[i] !== right[i]) {
      throw new Error(`sample ${i}: ${left[i]} !== ${right[i]}`)
    }
  }
}

describe('pcm playback stream', () => {
  it('plays one signal the same whether it arrived in one chunk or many', () => {
    const pcm = ramp(4000)
    for (const deviceRate of [48_000, 44_100]) {
      const whole = play([pcm], deviceRate)
      for (const size of [1, 17, 128]) {
        sameSamples(whole, play(chunksOf(pcm, size), deviceRate))
      }
    }
  })

  it('inserts no silence inside a split signal', () => {
    const out = play(chunksOf(ramp(4000), 10), 48_000)
    let ended = false
    for (const sample of out) {
      if (sample === 0) ended = true
      else expect(ended).toBe(false)
    }
    expect(ended).toBe(true)
  })

  it('waits for the prebuffer, and plays a short turn as soon as it ends', () => {
    const deviceRate = 48_000
    const below = new Int16Array(Math.floor(SOURCE_RATE * PREBUFFER_SECONDS) - 100).fill(16000)
    const waiting = new PcmStream()
    waiting.config(SOURCE_RATE)
    waiting.push(below)
    const silent = render(waiting, Math.floor(deviceRate * START_STALL_SECONDS) - 1, deviceRate)
    expect(silent.every((sample) => sample === 0)).toBe(true)

    const enough = new Int16Array(200).fill(16000)
    waiting.push(enough)
    const started = render(waiting, 480, deviceRate)
    expect(started.some((sample) => sample !== 0)).toBe(true)

    const short = new PcmStream()
    short.config(SOURCE_RATE)
    short.push(below)
    short.endTurn()
    const played = render(short, 480, deviceRate)
    expect(played.some((sample) => sample !== 0)).toBe(true)
  })

  it('fades out when the queue runs dry instead of dropping to silence', () => {
    const deviceRate = 48_000
    const samples = Math.ceil(SOURCE_RATE * PREBUFFER_SECONDS)
    const stream = new PcmStream()
    stream.config(SOURCE_RATE)
    stream.push(new Int16Array(samples).fill(16000))
    const out = render(stream, Math.ceil((samples * deviceRate) / SOURCE_RATE) + 2000, deviceRate)

    let peak = 0
    let loudAt = 0
    for (let i = 0; i < out.length; i += 1) {
      if (Math.abs(out[i]!) > peak) {
        peak = Math.abs(out[i]!)
        loudAt = i
      }
    }
    expect(peak).toBeGreaterThan(0.4)
    const tail = out.subarray(loudAt)
    const silentAt = tail.findIndex((sample) => sample === 0)
    expect(silentAt).toBeGreaterThan(deviceRate * 0.004)
    for (let i = 1; i < silentAt; i += 1) {
      expect(tail[i]!).toBeLessThanOrEqual(tail[i - 1]! + 1e-6)
    }
    let biggestStep = 0
    for (let i = 1; i < out.length; i += 1) {
      biggestStep = Math.max(biggestStep, Math.abs(out[i]! - out[i - 1]!))
    }
    expect(biggestStep).toBeLessThan(0.02)
  })

  it('drops buffered audio on flush and starts the next turn from the beginning', () => {
    const deviceRate = 48_000
    const pcm = ramp(4000)
    const stream = new PcmStream()
    stream.config(SOURCE_RATE)
    stream.push(pcm)
    render(stream, 1000, deviceRate)
    stream.flush()
    expect(stream.takeMessages()).toContainEqual({ type: 'level', level: 0 })
    const silenced = render(stream, 200, deviceRate)
    expect(silenced.every((sample) => sample === 0)).toBe(true)

    stream.push(pcm)
    const fresh = new PcmStream()
    fresh.config(SOURCE_RATE)
    fresh.push(pcm)
    const length = Math.ceil((pcm.length * deviceRate) / SOURCE_RATE)
    sameSamples(render(stream, length, deviceRate), render(fresh, length, deviceRate))
  })
})

describe('pcm frame assembler', () => {
  it('keeps a trailing odd byte for the next frame and drops it on reset', () => {
    const assembler = new PcmFrameAssembler()
    const first = assembler.push(Uint8Array.from([0x01, 0x00, 0x02]).buffer)
    expect(Array.from(first!)).toEqual([1])

    const second = assembler.push(Uint8Array.from([0x00]).buffer)
    expect(Array.from(second!)).toEqual([2])
    expect(assembler.push(Uint8Array.from([0xff]).buffer)).toBeNull()

    const third = assembler.push(Uint8Array.from([0x7f]).buffer)
    expect(Array.from(third!)).toEqual([32767])

    assembler.reset()
    expect(assembler.push(Uint8Array.from([0x00]).buffer)).toBeNull()
  })
})

describe('classic worklet source', () => {
  it('strips the exports so the playback worklet loads as a classic script', () => {
    const source = readFileSync(resolve('src/services/liveVoice/pcm-playback.worklet.js'), 'utf8')
    const classic = classicWorkletSource(source)
    expect(classic).not.toMatch(/^export /m)
    expect(classic).toContain('class PcmStream')
    expect(classic).toContain("registerProcessor('pcm-playback'")
  })
})
