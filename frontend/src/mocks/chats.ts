export interface Chat {
  id: string
  title: string
  type: 'personal' | 'widget'
  timestamp: string
  archived?: boolean
}

export const mockChats: Chat[] = [
  { id: '1', title: 'Getting started with Vue', type: 'personal', timestamp: '2 hours ago' },
  { id: '2', title: 'API integration help', type: 'widget', timestamp: '5 hours ago' },
  { id: '3', title: 'Debugging layout issues', type: 'personal', timestamp: 'Yesterday' },
  { id: '4', title: 'Component architecture', type: 'personal', timestamp: 'Yesterday' },
  { id: '5', title: 'State management patterns', type: 'widget', timestamp: '2 days ago' },
  { id: '6', title: 'Performance optimization', type: 'personal', timestamp: '3 days ago', archived: true },
  { id: '7', title: 'Testing strategies', type: 'widget', timestamp: '4 days ago', archived: true },
  { id: '8', title: 'Old project discussion', type: 'personal', timestamp: '1 week ago', archived: true },
  { id: '9', title: 'Widget setup guide', type: 'widget', timestamp: '2 weeks ago', archived: true },
]

