import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import type { APIRequestContext } from '@playwright/test'
import { z } from 'zod'
import { getApiUrl } from '../config/config'

export const DEFAULTS_PATH = '/api/v1/config/models/defaults'

/**
 * Local contract for the fields read here. The E2E suite must not import the
 * gitignored `src/generated/api-schemas.ts` (see provider-keys.spec.ts).
 */
const InstanceDefaultsSchema = z
  .object({
    defaults: z.record(z.string(), z.number().int().nullable()).optional(),
    stored: z.record(z.string(), z.number().int().nullable()).optional(),
  })
  .passthrough()

/**
 * Instance defaults as they were before the run. Kept outside test-results/,
 * which Playwright empties at the start of every run.
 */
const SNAPSHOT_FILE = fileURLToPath(new URL('../.model-defaults-snapshot.json', import.meta.url))

type Headers = Record<string, string>

/**
 * Remember the instance defaults the run is about to replace, so a local dev
 * stack gets its real models back afterwards. The saved row wins over the
 * model that currently answers: with Ollama down, VECTORIZE answers with a
 * cloud embedder, and writing that back would switch the embedding model.
 * A snapshot left by a run that never reached its teardown is kept: the live
 * values are TestProvider ones. Test-stub BIDs (< 1) are never remembered.
 */
export async function snapshotInstanceDefaults(
  ctx: APIRequestContext,
  headers: Headers,
  capabilities: string[]
): Promise<void> {
  if (process.env.CI || existsSync(SNAPSHOT_FILE)) return

  const res = await ctx.get(`${getApiUrl()}${DEFAULTS_PATH}?scope=instance`, { headers })
  if (!res.ok()) {
    throw new Error(`Reading instance defaults failed: ${res.status()} ${await res.text()}`)
  }
  const body = InstanceDefaultsSchema.parse(await res.json())
  const kept: Record<string, number> = {}
  for (const capability of capabilities) {
    const id = body.stored?.[capability] ?? body.defaults?.[capability]
    if (typeof id === 'number' && id >= 1) kept[capability] = id
  }
  writeFileSync(SNAPSHOT_FILE, JSON.stringify(kept, null, 2))
}

export async function restoreInstanceDefaults(
  ctx: APIRequestContext,
  headers: Headers
): Promise<void> {
  if (process.env.CI || !existsSync(SNAPSHOT_FILE)) return

  const defaults = JSON.parse(readFileSync(SNAPSHOT_FILE, 'utf8')) as Record<string, number>
  if (Object.keys(defaults).length > 0) {
    const res = await ctx.post(`${getApiUrl()}${DEFAULTS_PATH}`, {
      headers,
      data: { defaults, global: true },
    })
    if (!res.ok()) {
      throw new Error(`Restoring instance defaults failed: ${res.status()} ${await res.text()}`)
    }
  }
  rmSync(SNAPSHOT_FILE)
}
