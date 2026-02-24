import { type Page } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { execSync } from 'child_process'

/**
 * TTS provider: 'piper' (local, free) or 'openai' (remote, higher quality).
 * Set via TTS_PROVIDER env var. Defaults to piper when TTS_URL is set, openai otherwise.
 */
type TtsProvider = 'piper' | 'openai'

const TTS_URL = process.env.TTS_URL || 'http://127.0.0.1:10200'
const OPENAI_API_KEY = process.env.OPENAI_API_KEY || ''
const OPENAI_TTS_MODEL = process.env.OPENAI_TTS_MODEL || 'tts-1'
const OPENAI_TTS_VOICE = process.env.OPENAI_TTS_VOICE || 'nova'
const TTS_VOICE = process.env.TTS_VOICE || 'en_US-lessac-medium'
const TTS_SPEED = parseFloat(process.env.TTS_SPEED || '1.0')

function resolveProvider(): TtsProvider {
  const explicit = process.env.TTS_PROVIDER?.toLowerCase()
  if (explicit === 'openai' || explicit === 'piper') return explicit
  return OPENAI_API_KEY ? 'openai' : 'piper'
}

interface NarrationClip {
  text: string
  wavPath: string
  offsetMs: number
  durationMs: number
}

/**
 * Generates TTS narration clips timed to a Playwright scenario.
 *
 * During recording, each say() call synthesizes audio via the configured TTS
 * provider and pauses the browser for the clip's duration so timing stays in sync.
 *
 * Supports two providers:
 *   - **piper** (local): Self-hosted Piper TTS, free, robotic voice
 *   - **openai** (remote): OpenAI TTS API, paid, natural voice
 *
 * After the test finishes and Playwright writes the video, call buildAudioTrack()
 * to produce a combined WAV, then use merge-narration.sh to combine video + audio.
 */
export class Narrator {
  private clips: NarrationClip[] = []
  private startTime: number = 0
  private tempDir: string
  private voice: string
  private speed: number
  private provider: TtsProvider

  constructor(
    private scenarioName: string,
    options?: { voice?: string; speed?: number; provider?: TtsProvider },
  ) {
    this.provider = options?.provider || resolveProvider()
    this.speed = options?.speed || TTS_SPEED
    this.tempDir = path.join(__dirname, '.narration-tmp')
    fs.mkdirSync(this.tempDir, { recursive: true })

    if (this.provider === 'openai') {
      this.voice = options?.voice || OPENAI_TTS_VOICE
      if (!OPENAI_API_KEY) {
        throw new Error('OPENAI_API_KEY env var required for openai TTS provider')
      }
      console.log(`  Narrator: using OpenAI TTS (model=${OPENAI_TTS_MODEL}, voice=${this.voice})`)
    } else {
      this.voice = options?.voice || TTS_VOICE
      console.log(`  Narrator: using Piper TTS (voice=${this.voice}, url=${TTS_URL})`)
    }
  }

  /** Call once at the start of the scenario to anchor timestamps. */
  start(): void {
    this.startTime = Date.now()
    this.clips = []
  }

  /**
   * Speak a narration line. Generates TTS audio, records the timestamp,
   * and pauses the page so the viewer has time to hear it.
   */
  async say(page: Page, text: string, extraPauseMs: number = 500): Promise<void> {
    const offsetMs = Date.now() - this.startTime
    const clipIndex = this.clips.length
    const ext = this.provider === 'openai' ? 'mp3' : 'wav'
    const wavPath = path.join(this.tempDir, `${this.scenarioName}-${clipIndex}.${ext}`)

    const audioBuffer = await this.synthesize(text)
    fs.writeFileSync(wavPath, Buffer.from(audioBuffer))

    const durationMs = getAudioDurationMs(wavPath)

    this.clips.push({ text, wavPath, offsetMs, durationMs })

    await page.waitForTimeout(durationMs + extraPauseMs)
  }

  /**
   * Build the combined audio track from all clips.
   * Returns the path to the combined WAV file.
   * Call this after the scenario but before cleanup.
   */
  buildAudioTrack(): string {
    const outputPath = path.join(
      __dirname,
      'videos',
      `${this.scenarioName}-narration.wav`,
    )
    fs.mkdirSync(path.dirname(outputPath), { recursive: true })

    if (this.clips.length === 0) {
      throw new Error('No narration clips recorded')
    }

    const inputs = this.clips.map((c) => `-i "${c.wavPath}"`).join(' ')
    const delays = this.clips
      .map((c, i) => `[${i}]adelay=${c.offsetMs}|${c.offsetMs}[d${i}]`)
      .join(';')
    const mixInputs = this.clips.map((_, i) => `[d${i}]`).join('')
    const amix = `${mixInputs}amix=inputs=${this.clips.length}:duration=longest:normalize=0`

    execSync(
      `ffmpeg -y ${inputs} -filter_complex "${delays};${amix}" -ar 22050 -ac 1 "${outputPath}"`,
      { stdio: 'pipe' },
    )

    for (const clip of this.clips) {
      try { fs.unlinkSync(clip.wavPath) } catch {}
    }

    return outputPath
  }

  /**
   * One-shot: build audio track and merge with the video.
   * videoPath must point to an existing file (call after Playwright flushes).
   */
  mergeIntoVideo(videoPath: string, outputPath?: string): string {
    const audioPath = this.buildAudioTrack()
    const out = outputPath || videoPath.replace(/\.webm$/, '-narrated.mp4')

    execSync(
      [
        'ffmpeg -y',
        `-i "${videoPath}"`,
        `-i "${audioPath}"`,
        '-c:v libx264 -preset fast -crf 23',
        '-c:a aac -b:a 128k',
        '-map 0:v:0 -map 1:a:0',
        '-shortest',
        '-r 25',
        `"${out}"`,
      ].join(' '),
      { stdio: 'pipe' },
    )

    try { fs.unlinkSync(audioPath) } catch {}
    return out
  }

  private async synthesize(text: string): Promise<ArrayBuffer> {
    if (this.provider === 'openai') {
      return this.synthesizeOpenAI(text)
    }
    return this.synthesizePiper(text)
  }

  private async synthesizePiper(text: string): Promise<ArrayBuffer> {
    const response = await fetch(`${TTS_URL}/api/tts`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        text,
        voice: this.voice,
        length_scale: this.speed,
      }),
    })

    if (!response.ok) {
      throw new Error(`Piper TTS failed (${response.status}): ${await response.text()}`)
    }

    return response.arrayBuffer()
  }

  private async synthesizeOpenAI(text: string): Promise<ArrayBuffer> {
    const response = await fetch('https://api.openai.com/v1/audio/speech', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${OPENAI_API_KEY}`,
      },
      body: JSON.stringify({
        model: OPENAI_TTS_MODEL,
        voice: this.voice,
        input: text,
        response_format: 'mp3',
        speed: this.speed,
      }),
    })

    if (!response.ok) {
      throw new Error(`OpenAI TTS failed (${response.status}): ${await response.text()}`)
    }

    return response.arrayBuffer()
  }
}

function getAudioDurationMs(filePath: string): number {
  const output = execSync(
    `ffprobe -v error -show_entries format=duration -of csv=p=0 "${filePath}"`,
    { encoding: 'utf-8' },
  )
  return Math.ceil(parseFloat(output.trim()) * 1000)
}
