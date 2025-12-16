export interface Widget {
  id: string
  userId: string
  integrationType: 'floating-button' | 'inline' | 'fullpage'
  primaryColor: string
  iconColor: string
  position: 'bottom-right' | 'bottom-left' | 'top-right' | 'top-left'
  autoMessage: string
  autoOpen: boolean
  aiPrompt: string
  defaultTheme?: 'light' | 'dark'
  previewUrl?: string
  createdAt: Date
  updatedAt: Date
}

export interface WidgetConfig {
  integrationType: 'floating-button' | 'inline' | 'fullpage'
  primaryColor: string
  iconColor: string
  position: 'bottom-right' | 'bottom-left' | 'top-right' | 'top-left'
  autoMessage: string
  autoOpen: boolean
  aiPrompt: string
  defaultTheme?: 'light' | 'dark'
  previewUrl?: string
}

export const mockWidgets: Widget[] = []

export const integrationTypes = [
  { value: 'floating-button', label: 'Floating Button' },
  { value: 'inline', label: 'Inline Embed' },
  { value: 'fullpage', label: 'Full Page' },
]

export const positions = [
  { value: 'bottom-right', label: 'Bottom Right' },
  { value: 'bottom-left', label: 'Bottom Left' },
  { value: 'top-right', label: 'Top Right' },
  { value: 'top-left', label: 'Top Left' },
]

export const aiPrompts = [
  { value: 'general', label: '(default) general' },
  { value: 'support', label: 'Customer Support' },
  { value: 'sales', label: 'Sales Assistant' },
  { value: 'technical', label: 'Technical Help' },
]

export const generateEmbedCode = (widgetId: string, userId: string): string => {
  return `var script = document.createElement('script');
script.src = 'https://app.synaplan.com/widget.php?uid=${userId}&widgetid=${widgetId}';
script.async = true;
document.head.appendChild(script);`
}
