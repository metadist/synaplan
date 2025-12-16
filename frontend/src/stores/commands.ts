import { defineStore } from 'pinia'
import { ref } from 'vue'

export interface Command {
  name: string
  description: string
  usage: string
  requiresArgs: boolean
  icon: string
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
    name: 'search',
    description: 'Search the web',
    usage: '/search [query]',
    requiresArgs: true,
    icon: 'mdi:magnify',
  },
]

export const useCommandsStore = defineStore('commands', () => {
  const commands = ref<Command[]>(commandsData)

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
