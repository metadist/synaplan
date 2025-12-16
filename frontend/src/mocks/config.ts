export interface WhatsAppChannel {
  id: string
  number: string
  handling: string
}

export interface EmailChannel {
  id: string
  email: string
  handling: string
}

export interface APIConfig {
  endpoint: string
  documentation: string
}

export interface WidgetConfig {
  activeDomain: string
  isActive: boolean
}

export const mockWhatsAppChannels: WhatsAppChannel[] = [
  { id: '1', number: '+16282253244', handling: 'default handling' },
  { id: '2', number: '+4915116038214', handling: 'default handling' }
]

export const mockEmailChannels: EmailChannel[] = [
  { id: '1', email: 'smart@synaplan.net', handling: 'default handling' }
]

export const mockAPIConfig: APIConfig = {
  endpoint: 'https://synawork.com/api.php',
  documentation: 'Simple API calls with your personal API key'
}

export const mockWidgetConfig: WidgetConfig = {
  activeDomain: '',
  isActive: false
}

export const emailKeywordBase = 'smart+'
export const emailKeywordDomain = '@synaplan.net'

