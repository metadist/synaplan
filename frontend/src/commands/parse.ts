export interface ParsedCommand {
  command: string
  args: string[]
  raw: string
}

export function parseCommand(input: string): ParsedCommand | null {
  const trimmed = input.trim()
  if (!trimmed.startsWith('/')) {
    return null
  }

  const tokens: string[] = []
  let current = ''
  let inQuotes = false
  let escapeNext = false

  for (let i = 1; i < trimmed.length; i++) {
    const char = trimmed[i]

    if (escapeNext) {
      current += char
      escapeNext = false
      continue
    }

    if (char === '\\') {
      escapeNext = true
      continue
    }

    if (char === '"') {
      inQuotes = !inQuotes
      continue
    }

    if (char === ' ' && !inQuotes) {
      if (current) {
        tokens.push(current)
        current = ''
      }
      continue
    }

    current += char
  }

  if (current) {
    tokens.push(current)
  }

  if (tokens.length === 0) {
    return null
  }

  const [command, ...args] = tokens

  return {
    command: command.toLowerCase(),
    args,
    raw: trimmed,
  }
}

export function normalizeUrl(url: string): string {
  if (!/^https?:\/\//i.test(url)) {
    return `https://${url}`
  }
  return url
}
