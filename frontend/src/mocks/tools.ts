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
    id: 'doc-summary',
    name: 'Doc Summary',
    description: 'Automatically summarize documents and extract key information',
    category: 'Documents',
    icon: 'DocumentTextIcon',
    color: 'purple',
    tags: ['AI Generated', 'Text Processing'],
    commands: [
      {
        command: '/docs [text]',
        description: 'Searches your uploads for the specified text',
        parameters: 'Local Search, Multiple Formats',
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
