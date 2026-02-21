import { test } from '@playwright/test'
import { login, startNewChat, sendMessage, scrollResponse, pause } from '../helpers'
import { Narrator } from '../narrate'
import * as fs from 'fs'
import * as path from 'path'
import { execSync } from 'child_process'

/*
 * Scenario: Narrated Chat Demo
 *
 * Records a screen video with TTS narration baked in.
 * Phase 1 (test): Record video + generate narration audio clips
 * Phase 2 (afterAll): Merge audio into the video as an MP4
 */

const DEMO_EMAIL = process.env.DEMO_EMAIL || 'demo@synaplan.com'
const DEMO_PASSWORD = process.env.DEMO_PASSWORD || 'demo'
const VIDEO_DIR = path.join(__dirname, '..', 'videos')

let narrator: Narrator
let audioTrackPath: string

test('Demo: Narrated chat with voice commentary', async ({ page }) => {
  narrator = new Narrator('narrated-chat-demo')
  narrator.start()

  await narrator.say(page, 'Welcome to Synaplan. Let me show you how easy it is to get started.')

  await page.goto('/login')
  await page.waitForSelector('[data-testid="page-login"]')

  await narrator.say(page, 'First, we log in with our account credentials.')

  await login(page, DEMO_EMAIL, DEMO_PASSWORD)

  await narrator.say(page, 'We are now on the main chat page. Let us start a new conversation.')

  await startNewChat(page)

  await narrator.say(
    page,
    'We can ask any question. Synaplan connects to multiple AI providers to give you the best answer.',
  )

  await sendMessage(page, 'How long is the Great Wall of China?')

  await narrator.say(page, 'The AI is responding with a detailed answer. Let us scroll through it.')

  await scrollResponse(page, 4)

  await narrator.say(page, 'That is how simple it is to use Synaplan. Thank you for watching!')

  await pause(page, 2000)

  // Build the combined narration audio track while clips are still on disk
  audioTrackPath = narrator.buildAudioTrack()
})

test.afterAll(async () => {
  if (!audioTrackPath) return

  // Find the webm that Playwright wrote to the output dir
  const outputDir = path.join(VIDEO_DIR)
  const webmFiles = findWebmFiles(outputDir, 'narrated-chat-demo')

  if (webmFiles.length === 0) {
    console.log(`\n  No video found to merge. Audio track: ${audioTrackPath}`)
    console.log(`  Merge manually: ./merge-narration.sh <video.webm> ${audioTrackPath}\n`)
    return
  }

  const videoPath = webmFiles[0]
  const outputMp4 = path.join(VIDEO_DIR, 'narrated-chat-demo.mp4')

  try {
    execSync(
      [
        'ffmpeg -y',
        `-i "${videoPath}"`,
        `-i "${audioTrackPath}"`,
        '-c:v libx264 -preset fast -crf 23',
        '-c:a aac -b:a 128k',
        '-map 0:v:0 -map 1:a:0',
        '-shortest',
        '-r 25',
        `"${outputMp4}"`,
      ].join(' '),
      { stdio: 'pipe' },
    )

    try { fs.unlinkSync(audioTrackPath) } catch {}
    console.log(`\n  Narrated video: ${outputMp4}\n`)
  } catch (err) {
    console.error(`\n  Merge failed: ${err}`)
    console.log(`  Audio track: ${audioTrackPath}`)
    console.log(`  Video: ${videoPath}`)
    console.log(`  Merge manually: ./merge-narration.sh "${videoPath}" "${audioTrackPath}"\n`)
  }
})

function findWebmFiles(dir: string, nameFragment: string): string[] {
  const results: string[] = []
  if (!fs.existsSync(dir)) return results

  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const fullPath = path.join(dir, entry.name)
    if (entry.isDirectory() && entry.name.includes(nameFragment)) {
      const videoFile = path.join(fullPath, 'video.webm')
      if (fs.existsSync(videoFile)) results.push(videoFile)
    } else if (entry.isFile() && entry.name.endsWith('.webm') && entry.name.includes(nameFragment)) {
      results.push(fullPath)
    }
  }
  return results
}
