import { promises as fs } from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

const distDir = path.resolve(__dirname, '..', 'dist-widget')
const targetDir = path.resolve(__dirname, '..', '..', 'backend', 'public')
const filesToCopy = [
  { name: 'widget-loader.js', desc: 'Widget Loader (3.7 KB)' },
  { name: 'widget-full.js', desc: 'Full Widget (203 KB)' },
  { name: 'widget.js', desc: 'Legacy Widget (for backward compatibility)' }
]

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

  for (const file of filesToCopy) {
    const fileName = typeof file === 'string' ? file : file.name
    const desc = typeof file === 'string' ? '' : ` - ${file.desc}`
    const source = path.join(distDir, fileName)

    // Check if file exists
    try {
      await fs.access(source)
    } catch {
      console.log(`⏭️  ${fileName} not found, skipping...`)
      continue
    }

    if (!targetAvailable) {
      console.log(`ℹ️ Build artifact ready at ${path.relative(process.cwd(), source)}${desc}`)
      continue
    }

    const destination = path.join(targetDir, fileName)

    try {
      await fs.copyFile(source, destination)
      console.log(`✅ Copied ${fileName}${desc} → ${path.relative(process.cwd(), destination)}`)
    } catch (error) {
      console.error(`❌ Failed to copy ${fileName}:`, error.message)
      process.exit(1)
    }
  }
}

copyBundle()

