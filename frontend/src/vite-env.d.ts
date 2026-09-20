/// <reference types="vite/client" />

import 'vue-router'

declare module 'vue-router' {
  interface RouteMeta {
    i18n?: string[]
    titleKey?: string
    requiresAuth?: boolean
    public?: boolean
    allowGuest?: boolean
    requiresAdmin?: boolean
    helpId?: string
    context?: unknown
  }
}

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
