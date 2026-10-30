import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'
import vm from 'node:vm'

// The boot page is one file mounted by both dev compose files. Collabora
// starts with the standard checkout and stays behind the office profile on
// the minimal file, so the page must take its label from BOOT_STATUS_STACK.

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (...parts) => readFileSync(join(ROOT, ...parts), 'utf8')

const MINIMAL_OFFICE_START = 'docker compose -f docker-compose-minimal.yml --profile office up -d'

const pageBlocks = () => {
  const html = read('_docker/frontend/boot-status/index.html')
  const start = html.indexOf('const MINIMAL_OFFICE_START')
  const end = html.indexOf('const kbd =')
  assert.ok(start > 0 && end > start, 'boot page is missing the stack-specific Collabora copy')
  const source = html.slice(start, end)
  const run = (stack) => vm.runInNewContext(
    `${source}\nblockForStack(BLOCKS.find((block) => block.id === 'collabora'), ${JSON.stringify(stack)})`,
  )
  return { standard: run('standard'), minimal: run('minimal'), absent: run(undefined) }
}

test('standard boot page lists Collabora as on by default', () => {
  const { standard, absent } = pageBlocks()
  assert.equal(standard.defaultOn, true)
  assert.equal(standard.secondWave, true)
  assert.equal(standard.stop, 'docker compose stop collabora')
  assert.equal(absent.defaultOn, true)
})

test('minimal boot page lists Collabora as opt-in with the minimal compose command', () => {
  const { minimal } = pageBlocks()
  assert.equal(minimal.defaultOn, false)
  assert.equal(minimal.secondWave, undefined)
  assert.equal(minimal.stop, undefined)
  assert.equal(minimal.start, MINIMAL_OFFICE_START)
})

test('each dev compose file names its boot stack', () => {
  assert.match(read('docker-compose.yml'), /BOOT_STATUS_STACK:\s*standard/)
  assert.match(read('docker-compose-minimal.yml'), /BOOT_STATUS_STACK:\s*minimal/)
  assert.match(
    read('docker-compose-minimal.yml'),
    /collabora:[\s\S]*?profiles:\s*\[office\]/,
  )
})

test('boot status feed reports the stack the page branches on', () => {
  const server = read('_docker/frontend/boot-status/server.mjs')
  assert.match(server, /BOOT_STATUS_STACK === 'minimal' \? 'minimal' : 'standard'/)
  assert.match(server, /stack:\s*STACK/)
})
