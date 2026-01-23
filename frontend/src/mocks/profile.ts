export interface UserProfile {
  firstName: string
  lastName: string
  email: string
  phone: string
  companyName: string
  vatId: string
  street: string
  zipCode: string
  city: string
  country: string
  language: string
  timezone: string
  invoiceEmail: string
  memoriesEnabled: boolean
  canChangePassword?: boolean
  authProvider?: string
  isExternalAuth?: boolean
}

export interface Country {
  code: string
  name: string
}

export interface Language {
  code: string
  name: string
}

export interface Timezone {
  value: string
  label: string
}

export const mockProfile: UserProfile = {
  firstName: 'Yusuf',
  lastName: 'Senel',
  email: 'ys@metadist.de',
  phone: '+49 123 456789',
  companyName: 'Your Company GmbH',
  vatId: 'DE123456789',
  street: 'Von-Hünefeld-Str.8',
  zipCode: '40764',
  city: 'Langenfeld (Rheinland)',
  country: 'DE',
  language: 'en',
  timezone: 'Europe/Berlin',
  invoiceEmail: 'ys@metadist.de',
  memoriesEnabled: true,
}

export const countries: Country[] = [
  { code: 'DE', name: 'Germany' },
  { code: 'AT', name: 'Austria' },
  { code: 'CH', name: 'Switzerland' },
  { code: 'FR', name: 'France' },
  { code: 'NL', name: 'Netherlands' },
  { code: 'BE', name: 'Belgium' },
  { code: 'US', name: 'United States' },
  { code: 'GB', name: 'United Kingdom' },
]

export const languages: Language[] = [
  { code: 'en', name: 'English' },
  { code: 'de', name: 'Deutsch' },
  { code: 'fr', name: 'Français' },
  { code: 'es', name: 'Español' },
  { code: 'it', name: 'Italiano' },
  { code: 'pt', name: 'Português' },
  { code: 'nl', name: 'Nederlands' },
]

export const timezones: Timezone[] = [
  { value: 'Europe/Berlin', label: 'Europe/Berlin (GMT+1)' },
  { value: 'Europe/London', label: 'Europe/London (GMT+0)' },
  { value: 'Europe/Paris', label: 'Europe/Paris (GMT+1)' },
  { value: 'Europe/Amsterdam', label: 'Europe/Amsterdam (GMT+1)' },
  { value: 'America/New_York', label: 'America/New_York (GMT-5)' },
  { value: 'America/Los_Angeles', label: 'America/Los_Angeles (GMT-8)' },
  { value: 'Asia/Tokyo', label: 'Asia/Tokyo (GMT+9)' },
  { value: 'Australia/Sydney', label: 'Australia/Sydney (GMT+11)' },
]
