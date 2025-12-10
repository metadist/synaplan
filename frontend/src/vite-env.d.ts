/// <reference types="vite/client" />

declare module '*.css?inline' {
  const content: string
  export default content
}

// Augment Window interface for SynaplanWidget
interface SynaplanWidgetInstance {
  init: (config: Record<string, unknown>) => void
}

declare global {
  interface Window {
    SynaplanWidget?: SynaplanWidgetInstance
    SynaplanWidgetFull?: SynaplanWidgetInstance
  }
}

export {}
