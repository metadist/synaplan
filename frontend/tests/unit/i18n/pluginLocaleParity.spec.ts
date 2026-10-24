import { access, readdir, readFile } from 'node:fs/promises'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const LOCALES = ['de', 'en', 'es', 'fr', 'tr'] as const
const BANNED = [/\bDAG\b/i, /\bcron\b/i, /\bpayload\b/i, /\bworkflow\b/i, /\borchestration\b/i]

async function pluginsRoot(): Promise<string> {
  for (const candidate of ['/plugins', path.resolve(process.cwd(), '../plugins')]) {
    try {
      await access(candidate)
      return candidate
    } catch {
      // Next location.
    }
  }
  throw new Error('plugins directory not found')
}

function flatten(value: unknown, prefix = ''): Record<string, string> {
  const out: Record<string, string> = {}
  if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
    for (const [key, child] of Object.entries(value)) {
      const next = prefix ? `${prefix}.${key}` : key
      Object.assign(out, flatten(child, next))
    }
    return out
  }
  if (typeof value === 'string') out[prefix] = value
  return out
}

function placeholders(text: string): string[] {
  return [...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1]).sort()
}

describe('plugin locale parity', () => {
  it('requires five matching locale files for every plugin that has locales/', async () => {
    const root = await pluginsRoot()
    const ids = await readdir(root)
    const checked: string[] = []
    for (const id of ids) {
      const dir = path.join(root, id, 'frontend', 'locales')
      try {
        await access(dir)
      } catch {
        continue
      }
      checked.push(id)
      const files = Object.fromEntries(
        await Promise.all(
          LOCALES.map(async (lang) => {
            const raw = await readFile(path.join(dir, `${lang}.json`), 'utf8')
            return [lang, flatten(JSON.parse(raw))] as const
          })
        )
      )
      const english = files.en
      for (const lang of LOCALES) {
        expect(Object.keys(files[lang]).sort(), `${id} ${lang}`).toEqual(
          Object.keys(english).sort()
        )
        for (const [key, text] of Object.entries(files[lang])) {
          expect(placeholders(text), `${id} ${lang} ${key}`).toEqual(placeholders(english[key]))
          if (lang === 'en') {
            for (const pattern of BANNED) {
              expect(text, `${id} ${key}`).not.toMatch(pattern)
            }
          }
        }
      }
    }
    expect(checked.sort()).toEqual(['hello_world', 'synascriber'])
  })
})
