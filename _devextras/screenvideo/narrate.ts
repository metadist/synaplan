import { type Page } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import { execSync } from 'child_process'

const TTS_URL = process.env.TTS_URL || 'http://127.0.0.1:10200'
const TTS_VOICE = process.env.TTS_VOICE || 'en_US-lessac-medium'
const TTS_SPEED = parseFloat(process.env.TTS_SPEED || '1.0')

interface NarrationClip {
  text: string
  wavPath: string
  offsetMs: number
  durationMs: number
}

/**
 * Generates TTS narration clips timed to a Playwright scenario.
 *
 * During recording, each say() call synthesizes audio via the TTS service
 * and pauses the browser for the clip's duration so timing stays in sync.
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

  constructor(
    private scenarioName: string,
    options?: { voice?: string; speed?: number },
  ) {
    this.voice = options?.voice || TTS_VOICE
    this.speed = options?.speed || TTS_SPEED
    this.tempDir = path.join(__dirname, '.narration-tmp')
    fs.mkdirSync(this.tempDir, { recursive: true })
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
    const wavPath = path.join(this.tempDir, `${this.scenarioName}-${clipIndex}.wav`)

    const wavBuffer = await this.synthesize(text)
    fs.writeFileSync(wavPath, Buffer.from(wavBuffer))

    const durationMs = getWavDurationMs(wavPath)

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

    // Clean up individual clips
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
      throw new Error(`TTS synthesis failed (${response.status}): ${await response.text()}`)
    }

    return response.arrayBuffer()
  }
}

function getWavDurationMs(wavPath: string): number {
  const output = execSync(
    `ffprobe -v error -show_entries format=duration -of csv=p=0 "${wavPath}"`,
    { encoding: 'utf-8' },
  )
  return Math.ceil(parseFloat(output.trim()) * 1000)
}
