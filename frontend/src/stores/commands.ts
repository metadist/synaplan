import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import config from '@/stores/config'
import { i18n } from '@/i18n'

export interface Command {
  name: string
  description: string
  usage: string
  requiresArgs: boolean
  icon: string
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
  for (const plugin of config.plugins) {
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

export const useCommandsStore = defineStore('commands', () => {
  const commands = computed<Command[]>(() => [
    ...commandsData,
    ...(config.features.selfAware ? [helpCommand()] : []),
    ...pluginCommands(),
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
  }
})
