#!/usr/bin/env node
/**
 * Generate Zod schemas from OpenAPI and create readable aliases
 */

import { execFileSync } from 'child_process'
import { createHash } from 'crypto'
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'fs'
import { tmpdir } from 'os'
import { join } from 'path'

// Check if we should skip the fetch step (for CI)
const skipFetch = process.argv.includes('--skip-fetch')
// Leave the file untouched when it was generated from the spec the backend serves now
const ifChanged = process.argv.includes('--if-changed')

const filePath = 'src/generated/api-schemas.ts'
// First line of the generated file. tests/e2e/global-setup.ts compares it with
// the live backend spec, so the format must stay in sync with that check.
const SPEC_HASH_PREFIX = '// openapi-spec-sha256: '

async function loadSpec(source) {
  if (!/^https?:\/\//.test(source)) return readFileSync(source, 'utf-8')
  const response = await fetch(source)
  if (!response.ok) throw new Error(`HTTP ${response.status} from ${source}`)
  return response.text()
}

let specHash = null
// openapi-zod-client output before post-processing; in CI it is written to filePath
let rawPath = filePath
let workDir = null

if (!skipFetch) {
  const source = process.env.OPENAPI_SPEC || 'http://backend/api/doc.json'
  let spec
  try {
    spec = await loadSpec(source)
  } catch (error) {
    console.error(`❌ Could not load the OpenAPI spec: ${error.message}`)
    process.exit(1)
  }
  specHash = createHash('sha256').update(spec).digest('hex')

  if (
    ifChanged &&
    existsSync(filePath) &&
    readFileSync(filePath, 'utf-8').startsWith(SPEC_HASH_PREFIX + specHash)
  ) {
    process.exit(0)
  }

  // Step 1: Generate schemas using openapi-zod-client. The raw output goes to a temp
  // file so the dev server sees filePath change once, with the final content.
  console.log('🔄 Generating schemas from OpenAPI spec...')
  workDir = mkdtempSync(join(tmpdir(), 'synaplan-schemas-'))
  const specPath = join(workDir, 'openapi-spec.json')
  rawPath = join(workDir, 'api-schemas.raw.ts')
  writeFileSync(specPath, spec)
  execFileSync(
    'openapi-zod-client',
    [specPath, '-o', rawPath, '--template', 'schema-template.hbs'],
    { stdio: 'inherit' }
  )
}

// Step 2: Read the generated file
let content = readFileSync(rawPath, 'utf-8')
if (workDir) rmSync(workDir, { recursive: true, force: true })

// Step 3: Fix Zod v4 compatibility issues
console.log('🔧 Fixing Zod v4 compatibility...')

// Fix z.record() - Zod v4 requires keyType and valueType
// Replace: z.record(valueSchema) with z.record(z.string(), valueSchema)
// Handle multiline (z.record(\n), object literal, and inline value-schema patterns
content = content.replace(/z\.record\(\s*\n/g, 'z.record(z.string(), \n')
content = content.replace(/z\.record\(z\.object/g, 'z.record(z.string(), z.object')
content = content.replace(/z\.record\((z\.[a-zA-Z]+\(\))\)/g, 'z.record(z.string(), $1)')
// A value schema built from an OpenAPI oneOf, e.g. z.record(z.union([...]))
content = content.replace(/z\.record\(z\.union\(/g, 'z.record(z.string(), z.union(')
// additionalProperties: array or enum — Zod v4 still needs an explicit key type
content = content.replace(/z\.record\(z\.array\(/g, 'z.record(z.string(), z.array(')
content = content.replace(/z\.record\(z\.enum\(/g, 'z.record(z.string(), z.enum(')

// Step 4: Add readable aliases
console.log('✨ Creating readable aliases...')

// Convert snake_case to PascalCase
// get_admin_get_users_Response -> GetAdminGetUsersResponseSchema
function toPascalCase(str) {
  return str
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join('')
}

// Find all Response exports and create aliases
const responseExports = content.matchAll(/export const (\w+)_Response = /g)
const aliases = []

for (const match of responseExports) {
  const originalName = match[1]
  const readableName = toPascalCase(originalName) + 'ResponseSchema'

  // Skip if the names would be the same
  if (readableName !== originalName + '_Response') {
    aliases.push(`\n// Readable alias for ${originalName}_Response`)
    aliases.push(`export const ${readableName} = ${originalName}_Response`)
  }
}

// Add aliases at the end of the file
if (aliases.length > 0) {
  content += '\n\n// ============================================'
  content += '\n// Readable aliases for response schemas'
  content += '\n// ============================================'
  content += aliases.join('\n')
  content += '\n'
}

if (specHash) {
  content = `${SPEC_HASH_PREFIX}${specHash}\n${content}`
}

// Step 4: Write back
writeFileSync(filePath, content)

console.log('✅ Schema generation complete!')
console.log(`📝 Generated ${aliases.length / 2} readable aliases`)
