import { parseCommand } from './parse'

/**
 * Parse slash commands and return command info
 * Commands are now handled by the backend directly
 */
export function processCommand(input: string): { command: string; args: string[] } | null {
  const parsed = parseCommand(input)
  if (!parsed) {
    return null
  }

  return {
    command: parsed.command,
    args: parsed.args,
  }
}
