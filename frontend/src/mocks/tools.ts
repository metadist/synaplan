export interface Tool {
  id: string
  name: string
  description: string
  category: string
  icon: string
  color: string
  tags: string[]
  commands?: ToolCommand[]
}

export interface ToolCommand {
  command: string
  description: string
  parameters?: string
}

export const mockTools: Tool[] = [
  {
    id: 'chat-widget',
    name: 'Chat Widget',
    description: 'Interactive chat interface for communication',
    category: 'Communication',
    icon: 'ChatBubbleLeftRightIcon',
    color: 'blue',
    tags: ['Real-time', 'Interactive'],
    commands: [
      {
        command: '/chat [text]',
        description: 'Start a chat conversation with the provided text',
      },
    ],
  },
  {
    id: 'mail-handler',
    name: 'Mail Handler',
    description: 'Process and manage email communications automatically',
    category: 'Communication',
    icon: 'EnvelopeIcon',
    color: 'green',
    tags: ['Automated', 'Email'],
    commands: [
      {
        command: '/mail [action]',
        description: 'Handle mail operations like send, read, or organize',
      },
    ],
  },
]
