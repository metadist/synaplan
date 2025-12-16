export interface HistoryItem {
  id: number
  content: string
  timestamp: string
}

export const mockHistoryItems: HistoryItem[] = [
  {
    id: 1,
    content: 'hi',
    timestamp: '2023100130822',
  },
  {
    id: 2,
    content: 'generiere mir ein bild von einer riesen Kuh',
    timestamp: '2025030161339',
  },
  {
    id: 3,
    content: 'What is the weather like today?',
    timestamp: '2025030161344',
  },
]
