import { createApp } from 'vue'
import ChatWidget from './components/widgets/ChatWidget.vue'
import { i18n } from './i18n'
import widgetStyles from './style.css?inline'

const STYLE_ID = 'synaplan-widget-styles'

function ensureStylesInjected() {
  if (typeof document === 'undefined') {
    return
  }

  if (!document.getElementById(STYLE_ID)) {
    const styleEl = document.createElement('style')
    styleEl.id = STYLE_ID
    styleEl.textContent = widgetStyles
    document.head.appendChild(styleEl)
  }
}

interface WidgetConfig {
  widgetId: string
  position?: 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right'
  primaryColor?: string
  iconColor?: string
  buttonIcon?: string
  buttonIconUrl?: string
  defaultTheme?: 'light' | 'dark'
  autoOpen?: boolean
  openImmediately?: boolean
  autoMessage?: string
  apiUrl?: string
  messageLimit?: number
  maxFileSize?: number
  widgetTitle?: string
  isPreview?: boolean
  allowedDomains?: string[]
  allowFileUpload?: boolean
  fileUploadLimit?: number
}

/**
 * Synaplan Chat Widget - Standalone Bundle
 * 
 * Usage:
 * <script src="https://your-domain.com/widget.js"></script>
 * <script>
 *   SynaplanWidget.init({
 *     widgetId: 'wdg_abc123...',
 *     position: 'bottom-right',
 *     primaryColor: '#007bff',
 *     autoOpen: false
 *   });
 * </script>
 */
class SynaplanWidget {
  private config: WidgetConfig | null = null
  private app: any = null
  private container: HTMLElement | null = null

  /**
   * Initialize the widget
   */
  init(config: WidgetConfig) {
    if (!config.widgetId) {
      console.error('Synaplan Widget: widgetId is required')
      return
    }

    this.config = {
      position: 'bottom-right',
      primaryColor: '#007bff',
      iconColor: '#ffffff',
      defaultTheme: 'light',
      autoOpen: false,
      autoMessage: 'Hello! How can I help you today?',
      messageLimit: 50,
      maxFileSize: 10,
      widgetTitle: 'Chat Support',
      isPreview: false,
      apiUrl: import.meta.env.VITE_API_URL || 'http://localhost:8000',
      allowedDomains: [],
      allowFileUpload: false,
      fileUploadLimit: 3,
      ...config
    }

    // Wait for DOM to be ready
    const start = () => {
      this.prepareAndMount()
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', start)
    } else {
      start()
    }
  }

  private async prepareAndMount() {
    if (!this.config) {
      return
    }

    const shouldFetch = !this.config.isPreview
    if (shouldFetch) {
      const resolved = await this.fetchRemoteConfig()
      if (!resolved) {
        console.warn('Synaplan Widget: configuration not available, skipping mount')
        return
      }
    }

    this.mount()
  }

  private async fetchRemoteConfig(): Promise<boolean> {
    if (!this.config) {
      return false
    }

    const apiBase = (this.config.apiUrl || '').replace(/\/$/, '')
    if (!apiBase) {
      console.error('Synaplan Widget: apiUrl is required for remote configuration')
      return false
    }

    try {
      const headers: Record<string, string> = {
        Accept: 'application/json'
      }
      if (typeof window !== 'undefined' && window.location?.host) {
        headers['X-Widget-Host'] = window.location.host
      }

      const response = await fetch(`${apiBase}/api/v1/widget/${this.config.widgetId}/config`, {
        headers
      })

      if (!response.ok) {
        console.warn('Synaplan Widget: config request failed', response.status)
        return false
      }

      const data = await response.json()
      if (!data.success) {
        console.warn('Synaplan Widget: widget not active', data.reason || '')
        return false
      }

      const remoteConfig = data.config ?? {}

      this.config = {
        ...this.config,
        widgetTitle: data.name ?? this.config.widgetTitle ?? 'Chat Support',
        position: remoteConfig.position ?? this.config.position,
        primaryColor: remoteConfig.primaryColor ?? this.config.primaryColor,
        iconColor: remoteConfig.iconColor ?? this.config.iconColor,
        buttonIcon: remoteConfig.buttonIcon ?? this.config.buttonIcon,
        buttonIconUrl: remoteConfig.buttonIconUrl ?? this.config.buttonIconUrl,
        defaultTheme: remoteConfig.defaultTheme ?? this.config.defaultTheme,
        autoMessage: remoteConfig.autoMessage ?? this.config.autoMessage,
        messageLimit: typeof remoteConfig.messageLimit === 'number' ? remoteConfig.messageLimit : this.config.messageLimit,
        maxFileSize: typeof remoteConfig.maxFileSize === 'number' ? remoteConfig.maxFileSize : this.config.maxFileSize,
        allowedDomains: Array.isArray(remoteConfig.allowedDomains) ? remoteConfig.allowedDomains : this.config.allowedDomains,
        allowFileUpload: typeof remoteConfig.allowFileUpload === 'boolean' ? remoteConfig.allowFileUpload : this.config.allowFileUpload,
        fileUploadLimit: typeof remoteConfig.fileUploadLimit === 'number' ? remoteConfig.fileUploadLimit : this.config.fileUploadLimit,
        apiUrl: apiBase
      }

      return true
    } catch (error) {
      console.error('Synaplan Widget: failed to load configuration', error)
      return false
    }
  }

  /**
   * Mount the widget to the DOM
   */
  private mount() {
    if (!this.config) return

    // Create container
    this.container = document.createElement('div')
    this.container.id = 'synaplan-widget-root'
    document.body.appendChild(this.container)

    ensureStylesInjected()

    // Create Vue app
    this.app = createApp(ChatWidget, {
      widgetId: this.config.widgetId,
      position: this.config.position,
      primaryColor: this.config.primaryColor,
      iconColor: this.config.iconColor,
      buttonIcon: this.config.buttonIcon,
      buttonIconUrl: this.config.buttonIconUrl,
      defaultTheme: this.config.defaultTheme,
      autoOpen: this.config.autoOpen,
      openImmediately: this.config.openImmediately,
      autoMessage: this.config.autoMessage,
      messageLimit: this.config.messageLimit,
      maxFileSize: this.config.maxFileSize,
      widgetTitle: this.config.widgetTitle,
      apiUrl: this.config.apiUrl,
      allowFileUpload: this.config.allowFileUpload,
      fileUploadLimit: this.config.fileUploadLimit,
      isPreview: false
    })

    this.app.use(i18n)

    this.app.mount(this.container)

    console.log('✅ Synaplan Widget loaded successfully')
  }

  /**
   * Destroy the widget
   */
  destroy() {
    if (this.app) {
      this.app.unmount()
      this.app = null
    }

    if (this.container && this.container.parentNode) {
      this.container.parentNode.removeChild(this.container)
      this.container = null
    }

    this.config = null
  }

  /**
   * Open the widget programmatically
   */
  open() {
    // Emit custom event that ChatWidget can listen to
    if (!this.config) return
    window.dispatchEvent(new CustomEvent('synaplan-widget-open', {
      detail: { widgetId: this.config.widgetId }
    }))
  }

  /**
   * Close the widget programmatically
   */
  close() {
    if (!this.config) return
    window.dispatchEvent(new CustomEvent('synaplan-widget-close', {
      detail: { widgetId: this.config.widgetId }
    }))
  }

  /**
   * Start a brand new chat session (clears previous context)
   */
  startNewChat() {
    if (!this.config) return
    window.dispatchEvent(new CustomEvent('synaplan-widget-new-chat', {
      detail: { widgetId: this.config.widgetId }
    }))
  }
}

// Expose to global scope for loader
const widgetInstance = new SynaplanWidget()

// @ts-ignore
window.SynaplanWidgetFull = widgetInstance

export default widgetInstance

