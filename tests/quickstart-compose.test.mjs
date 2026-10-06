import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

// deploy/quickstart/compose.yaml is the one-file install: a person (or a
// Docker GUI such as Portainer or Dockge) gets this one file and, optionally, a
// .env — no git clone. Everything that would need the repository next to it, or
// a value the operator has to type first, breaks that promise.

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (...parts) => readFileSync(join(ROOT, ...parts), 'utf8')

const QUICKSTART = read('deploy', 'quickstart', 'compose.yaml')
const QUICKSTART_ENV = read('deploy', 'quickstart', '.env.example')
const SELFHOST = read('deploy', 'compose.yaml')
const SELFHOST_ENV = read('deploy', 'selfhost.env.example')

// `$${…}` is a literal `${…}` for the container shell, not Compose interpolation.
const interpolations = (text) =>
  [...text.matchAll(/(?<!\$)\$\{([A-Za-z_][A-Za-z0-9_]*)([^}$]*)/g)].map((match) => ({
    name: match[1],
    rest: match[2],
  }))

const imageRefs = (text) =>
  [...text.matchAll(/^\s*image:\s*["']?([^"'\s*][^"'\s]*)["']?\s*$/gm)].map((match) => match[1])

const centrifugoNamespaces = (text) => {
  const match = /^\s*CENTRIFUGO_CHANNEL_NAMESPACES:\s*'(.*)'\s*$/m.exec(text)
  assert.ok(match, 'CENTRIFUGO_CHANNEL_NAMESPACES is missing')
  return JSON.parse(match[1])
}

test('needs nothing from a git checkout', () => {
  assert.doesNotMatch(QUICKSTART, /^\s*build:/m, 'a build context needs the repository')
  assert.doesNotMatch(QUICKSTART, /^\s*env_file:/m, 'a required env_file fails without it')
  assert.doesNotMatch(QUICKSTART, /^\s*configs:/m, 'file-based configs need the repository')

  const bindMounts = [...QUICKSTART.matchAll(/^\s*-\s*["']?([.~/][^:\s]*):/gm)].map((m) => m[1])
  assert.deepEqual(bindMounts, [], 'host paths resolve differently in every Docker GUI')
})

test('starts without a single variable set', () => {
  assert.doesNotMatch(QUICKSTART, /(?<!\$)\$\{[A-Za-z_][A-Za-z0-9_]*:?\?/, 'a :? variable aborts the start')

  const withoutDefault = interpolations(QUICKSTART)
    .filter(({ rest }) => !rest.startsWith(':-'))
    .map(({ name }) => name)
  assert.deepEqual(withoutDefault, [], 'every variable needs a default')
})

test('runs every service by default', () => {
  assert.doesNotMatch(QUICKSTART, /^\s*profiles:/m, 'a profile hides a service from GUIs')
  assert.doesNotMatch(QUICKSTART, /^name:/m, 'GUIs name the project themselves')
})

test('every documented variable is read by the compose file', () => {
  const documented = [...QUICKSTART_ENV.matchAll(/^([A-Z][A-Z0-9_]*)=/gm)].map((m) => m[1])
  const used = new Set(interpolations(QUICKSTART).map(({ name }) => name))

  assert.ok(documented.length > 0)
  for (const name of documented) {
    assert.ok(used.has(name), `.env.example documents ${name}, but compose.yaml never reads it`)
  }
})

test('pins the same third-party images as the self-host compose', () => {
  const selfhostImages = new Set(imageRefs(SELFHOST))

  for (const image of imageRefs(QUICKSTART)) {
    assert.match(image, /@sha256:[a-f0-9]{64}$/, `${image} must be pinned by digest`)
    assert.ok(selfhostImages.has(image), `${image} differs from deploy/compose.yaml`)
  }
})

test('installs the same release as the self-host example', () => {
  const release = /^SYNAPLAN_VERSION=(.*)$/m.exec(SELFHOST_ENV)?.[1]?.trim()
  const image = /^x-app-image:\s*&app-image\s+ghcr\.io\/metadist\/synaplan:\$\{SYNAPLAN_VERSION:-([^}]+)\}\s*$/m.exec(
    QUICKSTART
  )?.[1]

  assert.ok(release)
  assert.equal(image, release)
})

test('configures the same realtime channels as the self-host compose', () => {
  assert.deepEqual(centrifugoNamespaces(QUICKSTART), centrifugoNamespaces(SELFHOST))
})
