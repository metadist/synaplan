import { promises as fs } from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

const distDir = path.resolve(__dirname, '..', 'dist-widget')
const targetDir = path.resolve(__dirname, '..', '..', 'backend', 'public')
const filesToCopy = ['widget.js']

async function ensureDistExists() {
  try {
    await fs.access(distDir)
  } catch {
    console.error('❌ Widget build output not found. Run "npm run build:widget" first.')
    process.exit(1)
  }
}

async function ensureTargetAvailable() {
  try {
    await fs.access(targetDir)
    return true
  } catch {
    console.warn('⚠️ Backend public directory not found - skipping automatic copy. Copy dist-widget/widget.js manually if needed.')
    return false
  }
}

async function copyBundle() {
  await ensureDistExists()

  const targetAvailable = await ensureTargetAvailable()

  for (const fileName of filesToCopy) {
    const source = path.join(distDir, fileName)

    if (!targetAvailable) {
      console.log(`ℹ️ Build artifact ready at ${path.relative(process.cwd(), source)}`)
      continue
    }

    const destination = path.join(targetDir, fileName)

    try {
      await fs.copyFile(source, destination)
      console.log(`✅ Copied ${fileName} → ${path.relative(process.cwd(), destination)}`)
    } catch (error) {
      console.error(`❌ Failed to copy ${fileName}:`, error.message)
      process.exit(1)
    }
  }
}

copyBundle()

