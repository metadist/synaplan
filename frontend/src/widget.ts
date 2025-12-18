/**
 * Synaplan Chat Widget - ES Module
 *
 * Single entry point with dynamic imports for optimal loading.
 * API URL detection works from both entry point and code-split chunks.
 *
 * Usage:
 * <script type="module">
 *   import SynaplanWidget from 'https://app.synaplan.com/widget.js'
 *   SynaplanWidget.init({
 *     widgetId: 'wdg_abc123',
 *     position: 'bottom-right',
 *     primaryColor: '#007bff',
 *     lazy: true,
 *     vueUrl: undefined
 *   })
 * </script>
 */

import { detectApiUrl } from './widget-utils'

interface WidgetConfig {
  widgetId: string
  position?: 'bottom-left' | 'bottom-right' | 'top-left' | 'top-right'
  primaryColor?: string
  iconColor?: string
  buttonIcon?: 'chat' | 'headset' | 'help' | 'robot' | 'message' | 'support' | string
  buttonIconUrl?: string
  defaultTheme?: 'light' | 'dark'
  autoOpen?: boolean
  autoMessage?: string
  apiUrl?: string
  messageLimit?: number
  maxFileSize?: number
  widgetTitle?: string
  isPreview?: boolean
  allowedDomains?: string[]
  allowFileUpload?: boolean
  fileUploadLimit?: number
  lazy?: boolean
  vueUrl?: string | null // undefined = default CDN, null = skip, string = custom URL
}

const DEFAULT_VUE_CDN = 'https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js'

class SynaplanWidget {
  private config: WidgetConfig | null = null
  private button: HTMLElement | null = null
  private app: any = null
  private container: HTMLElement | null = null
  private chatLoaded = false
  private chatLoading = false
  private shouldOpenImmediately = false
  private closeEventHandler: (() => void) | null = null

  async init(config: WidgetConfig) {
    if (!config.widgetId) {
      console.error('Synaplan Widget: widgetId is required')
      return
    }

    this.config = {
      position: 'bottom-right',
      primaryColor: '#007bff',
      iconColor: '#ffffff',
      buttonIcon: 'chat',
      defaultTheme: 'light',
      autoOpen: false,
      autoMessage: 'Hello! How can I help you today?',
      messageLimit: 50,
      maxFileSize: 10,
      widgetTitle: 'Chat Support',
      isPreview: false,
      allowedDomains: [],
      allowFileUpload: false,
      fileUploadLimit: 3,
      lazy: true, // Default to lazy loading
      ...config,
    }

    const start = async () => {
      if (this.config?.lazy) {
        await this.startLazy()
      } else {
        await this.startEager()
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', start)
    } else {
      start()
    }
  }

  /**
   * Lazy mode: Show button, load chat on click
   */
  private async startLazy() {
    await this.loadRemoteConfig()
    this.createButton()

    if (this.config?.autoOpen) {
      this.loadChat()
    }
  }

  /**
   * Eager mode: Load chat immediately
   */
  private async startEager() {
    await this.loadRemoteConfig()
    await this.loadChat()
  }

  private async loadRemoteConfig(): Promise<void> {
    if (!this.config || this.config.isPreview) return

    try {
      const apiUrl = this.config.apiUrl || ''
      const response = await fetch(`${apiUrl}/api/v1/widget/${this.config.widgetId}/config`, {
        headers: {
          'X-Widget-Host': window.location.host,
        },
      })

      if (!response.ok) return

      const data = await response.json()

      if (data.success && data.config) {
        this.config = {
          ...this.config,
          ...data.config,
          widgetTitle: data.name || this.config.widgetTitle,
        }
      }
    } catch (error) {
      console.warn('Synaplan Widget: Failed to load remote config', error)
    }
  }

  private getIconContent(): string {
    const iconColor = this.config?.iconColor || '#ffffff'

    if (this.config?.buttonIconUrl) {
      return `<img src="${this.config.buttonIconUrl}" alt="Chat" style="width: 32px; height: 32px; object-fit: contain;" />`
    }

    const icons: Record<string, string> = {
      chat: `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
      </svg>`,
      headset: `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 18v-6a9 9 0 0 1 18 0v6"></path>
        <path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path>
      </svg>`,
      help: `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"></circle>
        <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
        <line x1="12" y1="17" x2="12.01" y2="17"></line>
      </svg>`,
      robot: `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="10" rx="2"></rect>
        <circle cx="12" cy="5" r="2"></circle>
        <path d="M12 7v4"></path>
        <line x1="8" y1="16" x2="8" y2="16"></line>
        <line x1="16" y1="16" x2="16" y2="16"></line>
      </svg>`,
      message: `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
      </svg>`,
      support: `<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="${iconColor}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"></circle>
        <path d="M12 16v-4"></path>
        <path d="M12 8h.01"></path>
      </svg>`,
    }

    const iconType = this.config?.buttonIcon || 'chat'
    return icons[iconType] || icons.chat
  }

  private createButton() {
    if (!this.config) return

    this.button = document.createElement('div')
    this.button.id = 'synaplan-widget-button'

    const position = this.config.position || 'bottom-right'
    const positions = {
      'bottom-right': 'bottom: 20px; right: 20px;',
      'bottom-left': 'bottom: 20px; left: 20px;',
      'top-right': 'top: 20px; right: 20px;',
      'top-left': 'top: 20px; left: 20px;',
    }

    this.button.setAttribute(
      'style',
      `
      position: fixed;
      ${positions[position]}
      z-index: 999999;
      width: 60px;
      height: 60px;
      border-radius: 50%;
      background-color: ${this.config.primaryColor};
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: transform 0.2s, box-shadow 0.2s;
    `
    )

    this.button.innerHTML = this.getIconContent()

    this.button.addEventListener('mouseenter', () => {
      this.button!.style.transform = 'scale(1.1)'
      this.button!.style.boxShadow = '0 6px 20px rgba(0,0,0,0.2)'
    })

    this.button.addEventListener('mouseleave', () => {
      this.button!.style.transform = 'scale(1)'
      this.button!.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)'
    })

    this.button.addEventListener('click', () => {
      if (this.chatLoaded) {
        // Widget already loaded, just open it
        this.button!.style.display = 'none'
        this.open()
      } else {
        // First click: load and open widget
        this.shouldOpenImmediately = true
        this.loadChat()
      }
    })

    document.body.appendChild(this.button)
  }

  private async loadChat() {
    if (this.chatLoaded || this.chatLoading) return

    this.chatLoading = true

    // Show loading indicator on button
    if (this.button) {
      this.button.innerHTML = `
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="${this.config?.iconColor}" stroke-width="2">
          <circle cx="12" cy="12" r="10" opacity="0.25"></circle>
          <path d="M12 2 A10 10 0 0 1 22 12" opacity="1">
            <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="1s" repeatCount="indefinite"/>
          </path>
        </svg>
      `
    }

    try {
      // Load Vue.js if needed
      await this.ensureVueLoaded()

      // Dynamically import the chat widget components
      const [{ createApp }, ChatWidget, { i18n }, widgetStyles] = await Promise.all([
        import('vue'),
        import('./components/widgets/ChatWidget.vue'),
        import('./i18n'),
        import('./style.css?inline'),
      ])

      // Inject styles
      if (!document.getElementById('synaplan-widget-styles')) {
        const styleEl = document.createElement('style')
        styleEl.id = 'synaplan-widget-styles'
        styleEl.textContent = widgetStyles.default
        document.head.appendChild(styleEl)
      }

      // In lazy mode: hide button but keep it for later
      // In eager mode: remove button completely
      if (this.button) {
        if (this.config?.lazy) {
          this.button.style.display = 'none'
        } else {
          this.button.remove()
          this.button = null
        }
      }

      // Create container
      this.container = document.createElement('div')
      this.container.id = 'synaplan-widget-root'
      document.body.appendChild(this.container)

      // Mount Vue app
      this.app = createApp(ChatWidget.default, {
        widgetId: this.config!.widgetId,
        position: this.config!.position,
        primaryColor: this.config!.primaryColor,
        iconColor: this.config!.iconColor,
        defaultTheme: this.config!.defaultTheme,
        autoOpen: this.config!.autoOpen,
        openImmediately: this.shouldOpenImmediately, // Open immediately if user clicked button
        autoMessage: this.config!.autoMessage,
        messageLimit: this.config!.messageLimit,
        maxFileSize: this.config!.maxFileSize,
        widgetTitle: this.config!.widgetTitle,
        apiUrl: this.config!.apiUrl,
        allowFileUpload: this.config!.allowFileUpload,
        fileUploadLimit: this.config!.fileUploadLimit,
        hideButton: this.config!.lazy, // Hide ChatWidget's button in lazy mode
        isPreview: false,
      })

      this.app.use(i18n)
      this.app.mount(this.container)

      // In lazy mode: listen for close event to show button again
      if (this.config?.lazy && this.button) {
        const handleClose = () => {
          if (this.button) {
            this.button.style.display = 'flex'
            this.button.innerHTML = this.getIconContent() // Restore original icon
            this.shouldOpenImmediately = false
          }
        }
        this.closeEventHandler = handleClose
        window.addEventListener('synaplan-widget-close', handleClose)
      }

      this.chatLoaded = true
      console.log('✅ Synaplan Widget loaded successfully')
    } catch (error) {
      console.error('Failed to load Synaplan Widget:', error)

      // Restore button on error
      if (this.button) {
        this.button.innerHTML = this.getIconContent()
      } else if (this.config?.lazy) {
        this.createButton()
      }

      this.chatLoading = false
    }
  }

  private async ensureVueLoaded(): Promise<void> {
    const vueUrl = this.config?.vueUrl

    // null = skip Vue loading (already in page)
    if (vueUrl === null) {
      if (!(window as any).Vue) {
        throw new Error('Vue.js not found. Set vueUrl or include Vue.js in your page.')
      }
      return
    }

    // Check if Vue is already loaded
    if ((window as any).Vue) {
      return
    }

    // Load Vue from specified URL or default CDN
    const url = vueUrl || DEFAULT_VUE_CDN

    return new Promise((resolve, reject) => {
      const script = document.createElement('script')
      script.src = url
      script.onload = () => resolve()
      script.onerror = () => reject(new Error(`Failed to load Vue.js from ${url}`))
      document.head.appendChild(script)
    })
  }

  destroy() {
    // Remove event listener if it exists
    if (this.closeEventHandler) {
      window.removeEventListener('synaplan-widget-close', this.closeEventHandler)
      this.closeEventHandler = null
    }

    if (this.app) {
      this.app.unmount()
      this.app = null
    }

    if (this.container && this.container.parentNode) {
      this.container.parentNode.removeChild(this.container)
      this.container = null
    }

    if (this.button && this.button.parentNode) {
      this.button.parentNode.removeChild(this.button)
      this.button = null
    }

    this.config = null
    this.chatLoaded = false
  }

  open() {
    if (!this.config) return
    window.dispatchEvent(
      new CustomEvent('synaplan-widget-open', {
        detail: { widgetId: this.config.widgetId },
      })
    )
  }

  close() {
    if (!this.config) return
    window.dispatchEvent(
      new CustomEvent('synaplan-widget-close', {
        detail: { widgetId: this.config.widgetId },
      })
    )
  }

  startNewChat() {
    if (!this.config) return
    window.dispatchEvent(
      new CustomEvent('synaplan-widget-new-chat', {
        detail: { widgetId: this.config.widgetId },
      })
    )
  }
}

const widgetInstance = new SynaplanWidget()

export default {
  init(config: WidgetConfig) {
    if (!config.apiUrl) {
      config.apiUrl = detectApiUrl()
    }
    return widgetInstance.init(config)
  },
  destroy: () => widgetInstance.destroy(),
  open: () => widgetInstance.open(),
  close: () => widgetInstance.close(),
  startNewChat: () => widgetInstance.startNewChat(),
}
