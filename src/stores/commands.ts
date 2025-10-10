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
    name: 'list',
    description: 'Show all available commands',
    usage: '/list',
    requiresArgs: false,
    icon: 'mdi:format-list-bulleted',
  },
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
  {
    name: 'lang',
    description: 'Translate text to another language',
    usage: '/lang [code] [text]',
    requiresArgs: true,
    icon: 'mdi:translate',
    validate: (args: string[]) => {
      if (args.length < 2) {
        return { valid: false, error: 'Language code and text are required' }
      }
      const validCodes = ['en', 'de', 'fr', 'it', 'es', 'pt', 'nl']
      if (!validCodes.includes(args[0].toLowerCase())) {
        return { valid: false, error: `Invalid language code. Valid: ${validCodes.join(', ')}` }
      }
      return { valid: true }
    },
  },
  {
    name: 'web',
    description: 'Take a screenshot of a website',
    usage: '/web [url]',
    requiresArgs: true,
    icon: 'mdi:web',
    validate: (args: string[]) => {
      if (args.length === 0) {
        return { valid: false, error: 'URL is required' }
      }
      return { valid: true }
    },
  },
  {
    name: 'docs',
    description: 'Search local documentation',
    usage: '/docs [query]',
    requiresArgs: true,
    icon: 'mdi:file-document',
  },
  {
    name: 'link',
    description: 'Generate a login link',
    usage: '/link',
    requiresArgs: false,
    icon: 'mdi:link-variant',
  },
  {
    name: 'testpic',
    description: 'Test image renderer',
    usage: '/testpic',
    requiresArgs: false,
    icon: 'mdi:image-outline',
  },
  {
    name: 'testcode',
    description: 'Test code renderer',
    usage: '/testcode',
    requiresArgs: false,
    icon: 'mdi:code-tags',
  },
  {
    name: 'testvideo',
    description: 'Test video renderer',
    usage: '/testvideo',
    requiresArgs: false,
    icon: 'mdi:video-outline',
  },
  {
    name: 'testcombo',
    description: 'Test mixed content renderer',
    usage: '/testcombo',
    requiresArgs: false,
    icon: 'mdi:view-dashboard',
  },
  {
    name: 'testmix',
    description: 'Test all format renderers',
    usage: '/testmix',
    requiresArgs: false,
    icon: 'mdi:palette',
  },
]

export const useCommandsStore = defineStore('commands', () => {
  const commands = ref<Command[]>(commandsData)
  
  const recentCommands = ref<string[]>(
    JSON.parse(localStorage.getItem('recentCommands') || '[]')
  )

  const addRecentCommand = (command: string) => {
    const filtered = recentCommands.value.filter(c => c !== command)
    recentCommands.value = [command, ...filtered].slice(0, 10)
    localStorage.setItem('recentCommands', JSON.stringify(recentCommands.value))
  }

  const getCommand = (name: string): Command | undefined => {
    return commands.value.find(c => c.name === name)
  }

  return {
    commands,
    recentCommands,
    addRecentCommand,
    getCommand,
  }
})
