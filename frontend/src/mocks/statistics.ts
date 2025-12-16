export interface FileItem {
  id: string
  name: string
  type: 'pdf' | 'image' | 'excel' | 'document'
  direction: 'sent' | 'received'
  timestamp: Date
  description?: string
  user?: string
}

export interface Statistics {
  totalMessages: number
  messagesSent: number
  messagesReceived: number
  totalFiles: number
  filesSent: number
  filesReceived: number
}

export const mockStatistics: Statistics = {
  totalMessages: 129,
  messagesSent: 50,
  messagesReceived: 79,
  totalFiles: 32,
  filesSent: 15,
  filesReceived: 17,
}

export const mockLatestFiles: FileItem[] = [
  {
    id: '1',
    name: 'test1.pdf',
    type: 'pdf',
    direction: 'received',
    timestamp: new Date('2025-09-30T15:42:00'),
    description: 'was steht in dieser pdf?',
    user: 'mediamaker',
  },
  {
    id: '2',
    name: 'oai_1759246783_3432.png',
    type: 'image',
    direction: 'sent',
    timestamp: new Date('2025-09-30T15:39:00'),
    description: '/pic generiere ein bild von einem hund [Again-1759246783]',
    user: 'mediamaker',
  },
  {
    id: '3',
    name: 'oai_1759244995_3432.png',
    type: 'image',
    direction: 'sent',
    timestamp: new Date('2025-09-30T15:09:00'),
    description:
      '/pic Erzeuge ein hochauflösendes, fotorealistisches Bild eines freundlichen Golden Retrievers, der ...',
    user: 'mediamaker',
  },
  {
    id: '4',
    name: 'forecaast.xlsx',
    type: 'excel',
    direction: 'received',
    timestamp: new Date('2025-09-30T15:03:00'),
    description: 'worum geht es in dieser file?fasse es zusammen detailiert',
    user: 'mediamaker',
  },
  {
    id: '5',
    name: 'forecaast.xlsx',
    type: 'excel',
    direction: 'received',
    timestamp: new Date('2025-09-30T14:56:00'),
    description: 'worum gehts in dieser file',
    user: 'general',
  },
  {
    id: '6',
    name: 'secryptor_pro_forma_forecast.xlsx',
    type: 'excel',
    direction: 'received',
    timestamp: new Date('2025-09-30T14:54:00'),
    description: 'analysiere diese file',
    user: 'general',
  },
]
