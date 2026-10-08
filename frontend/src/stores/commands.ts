import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type { z } from 'zod'
import config from '@/stores/config'
import { useAuthStore } from '@/stores/auth'
import { i18n } from '@/i18n/instance'
import { httpClient } from '@/services/api/httpClient'
import { GetApiSavedPromptsListResponseSchema } from '@/generated/api-schemas'

export type SavedPromptRow = NonNullable<
  z.infer<typeof GetApiSavedPromptsListResponseSchema>['prompts']
>[number]

export interface Command {
  name: string
  description: string
  usage: string
  requiresArgs: boolean
  icon: string
  /** When set, choosing the command inserts this text instead of a slash command. */
  promptBody?: string
  /** True for commands contributed by an installed plugin's manifest. */
  isPlugin?: boolean
  /** Owning plugin id (plugin commands only). */
  pluginName?: string
  /** Plugin chat endpoint the command routes to, e.g. "/chat" (plugin commands only). */
  endpoint?: string
  validate?: (args: string[]) => { valid: boolean; error?: string }
}

export const commandsData: Command[] = [
  {
    name: 'pic',
    description: 'Generate an image from text',
    usage: '/pic [description]',
    requiresArgs: true,
    icon: 'mdi:image',
  },
  {
    name: 'vid',
    description: 'Generate a short video',
    usage: '/vid [description]',
    requiresArgs: true,
    icon: 'mdi:video',
  },
  {
    name: 'tts',
    description: 'Generate audio from text',
    usage: '/tts [text to speech]',
    requiresArgs: true,
    icon: 'mdi:microphone',
  },
  {
    name: 'search',
    description: 'Search the web',
    usage: '/search [query]',
    requiresArgs: true,
    icon: 'mdi:magnify',
  },
]

function helpCommand(): Command {
  return {
    name: 'help',
    description: String(i18n.global.t('selfAware.helpCommand.description')),
    usage: '/help',
    requiresArgs: false,
    icon: 'mdi:help-circle-outline',
  }
}

/**
 * Slash-commands contributed by installed plugins via their manifest
 * `chatCommands`, exposed through the runtime config. This is the generic seam
 * that lets any plugin register a `/command` in the composer — no core change
 * per plugin.
 */
export function pluginCommands(): Command[] {
  const result: Command[] = []
  for (const plugin of config?.plugins ?? []) {
    const chatCommands = plugin.chatCommands
    if (!chatCommands) {
      continue
    }
    for (const entry of chatCommands) {
      const name = (entry.command ?? '').replace(/^\//, '')
      const endpoint = entry.endpoint ?? ''
      if (!name || !endpoint) {
        continue
      }
      result.push({
        name,
        description: entry.description || `Talk to the ${plugin.name} plugin`,
        usage: `/${name} [message]`,
        requiresArgs: true,
        icon: 'mdi:puzzle-outline',
        isPlugin: true,
        pluginName: plugin.name,
        endpoint: endpoint.startsWith('/') ? endpoint : `/${endpoint}`,
      })
    }
  }
  return result
}

function savedPromptCommand(row: SavedPromptRow): Command {
  return {
    name: row.command,
    description: row.name || row.command,
    usage: `/${row.command}`,
    requiresArgs: false,
    icon: 'mdi:text-box-outline',
    promptBody: row.body,
  }
}

export const useCommandsStore = defineStore('commands', () => {
  const savedPrompts = ref<Command[]>([])
  // Only the newest request may write the list, so a slow earlier response
  // cannot bring back a prompt that was deleted in the meantime.
  let savedPromptsRequest = 0

  function setSavedPrompts(rows: readonly SavedPromptRow[]): void {
    savedPromptsRequest += 1
    savedPrompts.value = rows.filter((row) => row.command && row.body).map(savedPromptCommand)
  }

  /**
   * Fetch the saved prompts again. The composer calls this each time the
   * slash menu opens, so a prompt created or deleted on Prompts is listed
   * without a reload. A failed request keeps the list from the last load.
   */
  async function loadSavedPrompts(): Promise<void> {
    // The endpoint is signed-in only; a 401 would send a guest to the login page.
    if (!useAuthStore().isAuthenticated) {
      savedPromptsRequest += 1
      savedPrompts.value = []
      return
    }
    const request = ++savedPromptsRequest
    try {
      const data = await httpClient('/api/v1/saved-prompts', {
        schema: GetApiSavedPromptsListResponseSchema,
      })
      if (request !== savedPromptsRequest) return
      setSavedPrompts(data.prompts ?? [])
    } catch {
      // Keep the previous list: a failed refresh must not empty the menu.
    }
  }

  const commands = computed<Command[]>(() => [
    ...commandsData,
    ...(config?.features?.selfAware ? [helpCommand()] : []),
    ...pluginCommands(),
    ...savedPrompts.value,
  ])

  const recentCommands = ref<string[]>(JSON.parse(localStorage.getItem('recentCommands') || '[]'))

  const addRecentCommand = (command: string) => {
    const filtered = recentCommands.value.filter((c) => c !== command)
    recentCommands.value = [command, ...filtered].slice(0, 10)
    localStorage.setItem('recentCommands', JSON.stringify(recentCommands.value))
  }

  const getCommand = (name: string): Command | undefined => {
    return commands.value.find((c) => c.name === name)
  }

  return {
    commands,
    recentCommands,
    addRecentCommand,
    getCommand,
    loadSavedPrompts,
    setSavedPrompts,
  }
})
