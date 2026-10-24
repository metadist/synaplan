/**
 * Loads a plugin's locale files and builds the `t` passed to `mount`.
 * Keys are the plugin's own (`page.title`), never Synaplan host keys.
 */

export type PluginCopy = Record<string, unknown>

export async function readPluginLocale(baseUrl: string, lang: string): Promise<PluginCopy | null> {
  try {
    const response = await fetch(`${baseUrl}/locales/${lang}.json`)
    if (!response.ok) return null
    const body: unknown = await response.json()
    return body !== null && typeof body === 'object' && !Array.isArray(body)
      ? (body as PluginCopy)
      : null
  } catch {
    return null
  }
}

export function lookupPluginString(copy: PluginCopy, key: string): string | null {
  const parts = key.split('.')
  let current: unknown = copy
  for (const part of parts) {
    if (current === null || typeof current !== 'object' || Array.isArray(current)) return null
    current = (current as Record<string, unknown>)[part]
  }
  return typeof current === 'string' ? current : null
}

/** Drops a plugin load that finished after a newer language or page took over. */
export function createLoadGate() {
  let generation = 0
  return {
    begin(): number {
      generation += 1
      return generation
    },
    cancel(): void {
      generation += 1
    },
    isCurrent(token: number): boolean {
      return token === generation
    },
  }
}

export function pluginTranslator(local: PluginCopy | null, english: PluginCopy | null) {
  return (key: string, params: Record<string, string | number> = {}) => {
    const template =
      (local && lookupPluginString(local, key)) ||
      (english && lookupPluginString(english, key)) ||
      key
    return template.replace(/\{(\w+)\}/g, (_, name: string) =>
      params[name] != null ? String(params[name]) : ''
    )
  }
}
