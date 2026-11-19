import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

// deploy/coolify/synaplan.yaml is pasted into Coolify's "Docker Compose Empty"
// resource today and becomes templates/compose/synaplan.yaml in Coolify's
// catalog later. These checks hold the rules from Coolify's service template
// guide and the secrets contract from deploy/coolify/README.md.

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (...parts) => readFileSync(join(ROOT, ...parts), 'utf8')

const COOLIFY = read('deploy', 'coolify', 'synaplan.yaml')
const QUICKSTART = read('deploy', 'quickstart', 'compose.yaml')

const imageRefs = (text) =>
  [...text.matchAll(/^\s*image:\s*["']?([^"'\s*][^"'\s]*)["']?\s*$/gm)].map((match) => match[1])

const environmentValue = (name) => {
  const match = new RegExp(`^\\s*${name}:\\s*(.*)$`, 'm').exec(COOLIFY)
  assert.ok(match, `${name} is missing`)
  return match[1].trim()
}

// Coolify reads every `# key: value` line as template metadata.
const HEADER_KEYS = ['documentation', 'slogan', 'category', 'tags', 'logo', 'port']

test('starts with the metadata header Coolify reads', () => {
  const header = COOLIFY.split('\n').slice(0, HEADER_KEYS.length)
  assert.deepEqual(
    header.map((line) => /^# ([a-z_]+): \S/.exec(line)?.[1]),
    HEADER_KEYS,
    'the first lines are the template header, in this order'
  )
  assert.match(COOLIFY, /^# port: 80$/m, 'the port is the one the web role serves')
})

test('stays within the comment budget of an upstream template', () => {
  const comments = COOLIFY.split('\n').filter((line) => /^\s*#/.test(line))
  assert.ok(comments.length <= 10, `found ${comments.length} comment lines, at most 10`)
})

test('leaves restart, container names and host ports to Coolify', () => {
  assert.doesNotMatch(COOLIFY, /^\s*container_name:/m)
  assert.doesNotMatch(COOLIFY, /^\s*restart:/m)
  assert.doesNotMatch(COOLIFY, /^\s*ports:/m, 'a published port bypasses the proxy')
  assert.doesNotMatch(COOLIFY, /^\s*build:/m)
  assert.doesNotMatch(COOLIFY, /^\s*env_file:/m)
  const bindMounts = [...COOLIFY.matchAll(/^\s*-\s*["']?([.~/][^:\s]*):/gm)].map((m) => m[1])
  assert.deepEqual(bindMounts, [], 'every persistent path is a named volume')
})

test('pins every image to a version tag', () => {
  for (const image of imageRefs(COOLIFY)) {
    assert.doesNotMatch(image, /:latest\b/, `${image} floats`)
    assert.match(image, /:[^:@/]*\d[^:@/]*$/, `${image} has no version tag`)
  }
})

test('runs the same third-party versions as the quickstart', () => {
  const quickstartTags = new Set(imageRefs(QUICKSTART).map((image) => image.split('@')[0]))
  const thirdParty = imageRefs(COOLIFY).filter((image) => !image.startsWith('ghcr.io/metadist/'))

  assert.ok(thirdParty.length >= 5)
  for (const image of thirdParty) {
    assert.ok(quickstartTags.has(image), `${image} differs from deploy/quickstart/compose.yaml`)
  }
})

test('serves the public address Coolify generates for the web role', () => {
  assert.match(COOLIFY, /^\s*SERVICE_URL_BACKEND_80: \$\{SERVICE_URL_BACKEND_80\}$/m)
  for (const name of [
    'APP_URL',
    'FRONTEND_URL',
    'REALTIME_ALLOWED_ORIGINS',
    'CENTRIFUGO_CLIENT_ALLOWED_ORIGINS',
  ]) {
    assert.equal(environmentValue(name), '${SERVICE_URL_BACKEND}', `${name} must follow the domain`)
  }
  assert.equal(environmentValue('REALTIME_PUBLIC_WS_URL'), '""', 'the WebSocket stays same-origin')
})

test('takes every secret from Coolify, never from a literal', () => {
  const secrets = {
    APP_SECRET: 'SERVICE_PASSWORD_64_APPSECRET',
    TOKEN_SECRET: 'SERVICE_PASSWORD_64_TOKENSECRET',
    DB_PASSWORD: 'SERVICE_PASSWORD_MARIADB',
    MARIADB_PASSWORD: 'SERVICE_PASSWORD_MARIADB',
    MARIADB_ROOT_PASSWORD: 'SERVICE_PASSWORD_MARIADBROOT',
    REALTIME_API_KEY: 'SERVICE_PASSWORD_64_REALTIMEAPI',
    CENTRIFUGO_HTTP_API_KEY: 'SERVICE_PASSWORD_64_REALTIMEAPI',
    REALTIME_TOKEN_SECRET: 'SERVICE_PASSWORD_64_REALTIMETOKEN',
    CENTRIFUGO_CLIENT_TOKEN_HMAC_SECRET_KEY: 'SERVICE_PASSWORD_64_REALTIMETOKEN',
    CENTRIFUGO_ADMIN_PASSWORD: 'SERVICE_PASSWORD_CENTRIFUGOADMIN',
    CENTRIFUGO_ADMIN_SECRET: 'SERVICE_PASSWORD_64_CENTRIFUGOSECRET',
  }
  for (const [name, generated] of Object.entries(secrets)) {
    assert.equal(environmentValue(name), `\${${generated}}`, `${name} must come from ${generated}`)
  }

  // Coolify takes everything after SERVICE_PASSWORD_ or SERVICE_PASSWORD_64_
  // as one identifier; an underscore in it would split the name.
  for (const [, identifier] of COOLIFY.matchAll(/SERVICE_PASSWORD_(?:64_)?([A-Za-z0-9_]+)/g)) {
    assert.doesNotMatch(identifier, /_/, `SERVICE_PASSWORD identifier ${identifier}`)
  }
})

test('deploys without a single variable typed first', () => {
  assert.doesNotMatch(COOLIFY, /(?<!\$)\$\{[A-Za-z_][A-Za-z0-9_]*:?\?/, 'a :? variable blocks the deploy')

  const undefaulted = [...COOLIFY.matchAll(/(?<!\$)\$\{([A-Za-z_][A-Za-z0-9_]*)([^}$]*)\}/g)]
    .filter(([, name, rest]) => !name.startsWith('SERVICE_') && !rest.startsWith(':-'))
    .map(([, name]) => name)
  assert.deepEqual(undefaulted, [], 'every operator variable needs a default')
})

test('keeps the database, the vectors, the queue and the uploads in volumes', () => {
  for (const mount of [
    'synaplan-mariadb:/var/lib/mysql',
    'synaplan-qdrant:/qdrant/storage',
    'synaplan-redis:/data',
    'synaplan-uploads:/var/www/backend/var/uploads',
  ]) {
    assert.ok(COOLIFY.includes(`- ${mount}`), `${mount} is not mounted`)
  }
})
