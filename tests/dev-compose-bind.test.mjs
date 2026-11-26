import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

// The development and test stacks ship fixed passwords, an open database port
// and admin tools. They are meant for one machine, so every published port
// binds to loopback unless the operator sets SYNAPLAN_BIND. A new port mapping
// without that prefix would put the service on every network the machine is on.

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (...parts) => readFileSync(join(ROOT, ...parts), 'utf8')

const FILES = ['docker-compose.yml', 'docker-compose-minimal.yml', 'docker-compose.test.yml']

const LOOPBACK = ['${SYNAPLAN_BIND:-127.0.0.1}:', '127.0.0.1:']
// The backend reaches Keycloak through the host gateway, and on Linux a
// loopback-only port is not reachable from there.
const HOST_GATEWAY = '${SYNAPLAN_KEYCLOAK_BIND:-0.0.0.0}:'
const HOST_GATEWAY_SERVICES = new Set(['keycloak', 'keycloak_test'])

/** [{ service, mapping }] for every entry of a `ports:` list under `services:`. */
const publishedPorts = (text) => {
  const lines = text.split('\n')
  const start = lines.indexOf('services:')
  assert.ok(start >= 0, 'no services block')
  const found = []
  let service = null
  let inPorts = false
  for (const line of lines.slice(start + 1)) {
    if (/^[A-Za-z]/.test(line)) break
    const name = /^ {2}([A-Za-z0-9_-]+):\s*$/.exec(line)
    if (name) {
      service = name[1]
      inPorts = false
      continue
    }
    if (/^ {4}ports:/.test(line)) {
      inPorts = true
      continue
    }
    if (!inPorts) continue
    const entry = /^ {6}- ["']?([^"'\s#]+)/.exec(line)
    if (entry) {
      found.push({ service, mapping: entry[1] })
    } else if (/^ {0,4}\S/.test(line)) {
      inPorts = false
    }
  }
  return found
}

for (const file of FILES) {
  test(`${file} publishes every port on loopback by default`, () => {
    const ports = publishedPorts(read(file))
    assert.ok(ports.length > 0, 'no published ports found; the parser no longer matches the file')

    const open = ports
      .filter(({ service }) => !HOST_GATEWAY_SERVICES.has(service))
      .filter(({ mapping }) => !LOOPBACK.some((prefix) => mapping.startsWith(prefix)))
      .map(({ service, mapping }) => `${service}: ${mapping}`)
    assert.deepEqual(open, [], 'prefix the mapping with ${SYNAPLAN_BIND:-127.0.0.1}:')
  })

  test(`${file} keeps the Keycloak exception explicit`, () => {
    const keycloak = publishedPorts(read(file)).filter(({ service }) => HOST_GATEWAY_SERVICES.has(service))
    for (const { service, mapping } of keycloak) {
      assert.ok(mapping.startsWith(HOST_GATEWAY), `${service}: ${mapping} must use SYNAPLAN_KEYCLOAK_BIND`)
    }
  })

  test(`${file} does not log anyone into the database`, () => {
    assert.doesNotMatch(read(file), /^\s*PMA_(USER|PASSWORD):/m, 'phpMyAdmin must ask for the login')
  })
}

test('Keycloak is opt-in wherever it is published beyond loopback', () => {
  for (const file of FILES) {
    const text = read(file)
    for (const service of HOST_GATEWAY_SERVICES) {
      const block = new RegExp(`^  ${service}:\\n((?:    .*\\n|\\n)+)`, 'm').exec(text)
      if (!block) continue
      assert.match(block[1], /^ {4}profiles: \[oidc\]/m, `${file}: ${service} must stay behind the oidc profile`)
    }
  }
})
