import { access, readdir, readFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { ESLint } from 'eslint'
import prettier from 'prettier'

const frontendRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const formatCheck = process.argv.includes('--format-check')

async function pluginsDir() {
  for (const candidate of ['/plugins', path.resolve(frontendRoot, '../plugins')]) {
    try {
      await access(candidate)
      return candidate
    } catch {
      // Try the next location the autoloader uses.
    }
  }
  console.error('plugins directory not found (/plugins or ../plugins)')
  process.exit(1)
}

async function javascriptFiles(dir) {
  const entries = await readdir(dir, { withFileTypes: true })
  const found = []
  for (const entry of entries) {
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) {
      if (entry.name === 'synaform') continue
      found.push(...(await javascriptFiles(full)))
    } else if (entry.name.endsWith('.js')) {
      found.push(full)
    }
  }
  return found
}

const root = await pluginsDir()

const eslint = new ESLint({
  cwd: root,
  overrideConfigFile: path.join(frontendRoot, 'eslint.plugins.config.js'),
  errorOnUnmatchedPattern: false,
})
const results = await eslint.lintFiles(['**/*.js'])
const formatter = await eslint.loadFormatter('stylish')
const output = await formatter.format(results)
if (output) process.stdout.write(output)
if (results.some((result) => result.errorCount > 0 || result.fatalErrorCount > 0)) {
  process.exit(1)
}

if (!formatCheck) process.exit(0)

const prettierConfig = path.join(frontendRoot, '.prettierrc')
let dirty = false
for (const file of await javascriptFiles(root)) {
  const info = await prettier.getFileInfo(file, {
    ignorePath: path.join(frontendRoot, '.prettierignore'),
  })
  if (info.ignored || info.inferredParser === null) continue
  const source = await readFile(file, 'utf8')
  const options = {
    ...(await prettier.resolveConfig(file, { config: prettierConfig })),
    filepath: file,
  }
  if (!(await prettier.check(source, options))) {
    console.error(`Prettier would change ${file}`)
    dirty = true
  }
}
if (dirty) process.exit(1)
