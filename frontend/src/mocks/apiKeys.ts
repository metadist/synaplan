export interface APIKey {
  id: string
  name: string
  key: string
  status: 'active' | 'inactive'
  created: Date
  lastUsed: Date | null
  usageCount: number
}

export const mockAPIKeys: APIKey[] = [
  {
    id: '1',
    name: 'Server A',
    key: 'mock_key_********************************',
    status: 'active',
    created: new Date('2024-01-15'),
    lastUsed: new Date('2025-03-10'),
    usageCount: 1247
  },
  {
    id: '2',
    name: 'Production Server',
    key: 'mock_key_********************************',
    status: 'active',
    created: new Date('2024-02-20'),
    lastUsed: new Date('2025-03-12'),
    usageCount: 5632
  },
  {
    id: '3',
    name: 'Development Server',
    key: 'mock_key_********************************',
    status: 'inactive',
    created: new Date('2024-03-05'),
    lastUsed: new Date('2025-02-28'),
    usageCount: 892
  }
]

