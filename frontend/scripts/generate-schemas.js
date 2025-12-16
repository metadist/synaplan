#!/usr/bin/env node
/**
 * Generate Zod schemas from OpenAPI and create readable aliases
 */

import { execSync } from 'child_process'
import { readFileSync, writeFileSync } from 'fs'

// Check if we should skip the fetch step (for CI)
const skipFetch = process.argv.includes('--skip-fetch')

if (!skipFetch) {
  // Step 1: Generate schemas using openapi-zod-client
  console.log('🔄 Generating schemas from OpenAPI spec...')
  execSync(
    'openapi-zod-client http://backend/api/doc.json -o src/generated/api-schemas.ts --template schema-template.hbs',
    {
      stdio: 'inherit',
    }
  )
}

// Step 2: Read the generated file
const filePath = 'src/generated/api-schemas.ts'
let content = readFileSync(filePath, 'utf-8')

// Step 3: Fix Zod v4 compatibility issues
console.log('🔧 Fixing Zod v4 compatibility...')

// Fix z.record() - Zod v4 requires keyType and valueType
// Replace: z.record(valueSchema) with z.record(z.string(), valueSchema)
content = content.replace(/z\.record\(\s*\n/g, 'z.record(z.string(), \n')

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

// Step 4: Write back
writeFileSync(filePath, content)

console.log('✅ Schema generation complete!')
console.log(`📝 Generated ${aliases.length / 2} readable aliases`)
