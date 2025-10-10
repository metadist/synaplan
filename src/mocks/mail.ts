export interface MailConfig {
  mailServer: string
  port: number
  protocol: 'IMAP' | 'POP3'
  security: 'SSL/TLS' | 'STARTTLS' | 'None'
  username: string
  password: string
  checkInterval: number
  deleteAfter: boolean
}

export interface Department {
  id: string
  email: string
  rules: string
  isDefault: boolean
}

export interface SavedMailHandler {
  id: string
  name: string
  config: MailConfig
  departments: Department[]
  status: 'active' | 'inactive' | 'error'
  lastTested?: Date
  createdAt: Date
  updatedAt: Date
}

export const protocolOptions = [
  { value: 'IMAP', label: 'IMAP' },
  { value: 'POP3', label: 'POP3' }
]

export const securityOptions = [
  { value: 'SSL/TLS', label: 'SSL/TLS' },
  { value: 'STARTTLS', label: 'STARTTLS' },
  { value: 'None', label: 'None' }
]

export const checkIntervalOptions = [
  { value: 5, label: '5 minutes' },
  { value: 10, label: '10 minutes' },
  { value: 15, label: '15 minutes' },
  { value: 30, label: '30 minutes' },
  { value: 60, label: '1 hour' }
]

export const defaultMailConfig: MailConfig = {
  mailServer: '',
  port: 993,
  protocol: 'IMAP',
  security: 'SSL/TLS',
  username: '',
  password: '',
  checkInterval: 10,
  deleteAfter: false
}

export const mockDepartments: Department[] = []

export const mockMailHandlers: SavedMailHandler[] = [
  {
    id: '1',
    name: 'Support Email',
    config: {
      mailServer: 'imap.example.com',
      port: 993,
      protocol: 'IMAP',
      security: 'SSL/TLS',
      username: 'support@example.com',
      password: '••••••••',
      checkInterval: 5,
      deleteAfter: false
    },
    departments: [
      {
        id: '1',
        email: 'support@example.com',
        rules: 'subject:support',
        isDefault: true
      },
      {
        id: '2',
        email: 'tech@example.com',
        rules: 'subject:technical',
        isDefault: false
      }
    ],
    status: 'active',
    lastTested: new Date('2025-10-08'),
    createdAt: new Date('2025-09-15'),
    updatedAt: new Date('2025-10-08')
  },
  {
    id: '2',
    name: 'Sales Inquiries',
    config: {
      mailServer: 'mail.company.com',
      port: 993,
      protocol: 'IMAP',
      security: 'SSL/TLS',
      username: 'sales@company.com',
      password: '••••••••',
      checkInterval: 10,
      deleteAfter: false
    },
    departments: [
      {
        id: '3',
        email: 'sales@company.com',
        rules: 'subject:quote,price',
        isDefault: true
      }
    ],
    status: 'active',
    lastTested: new Date('2025-10-05'),
    createdAt: new Date('2025-09-20'),
    updatedAt: new Date('2025-10-05')
  }
]

