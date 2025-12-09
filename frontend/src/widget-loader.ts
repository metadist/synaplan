/**
 * Synaplan Widget Loader (Lightweight)
 *
 * This small loader script only creates the floating button.
 * The full chat widget is loaded on-demand when the user clicks the button.
 *
 * Usage:
 * <script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>
 * <script src="https://your-domain.com/widget-loader.js"></script>
 * <script>
 *   SynaplanWidget.init({
 *     widgetId: 'wdg_abc123...',
 *     position: 'bottom-right',
 *     primaryColor: '#007bff'
 *   });
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
  allowFileUpload?: boolean
  fileUploadLimit?: number
}

class SynaplanWidgetLoader {
  private config: WidgetConfig | null = null
  private button: HTMLElement | null = null
  private fullWidgetLoaded = false
  private fullWidgetLoading = false

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
      apiUrl: detectApiUrl(),
      allowFileUpload: false,
      fileUploadLimit: 3,
      ...config
    }

    const start = async () => {
      // Load remote config to get custom icon
      await this.loadRemoteConfig()
      
      this.createButton()
      if (this.config?.autoOpen) {
        this.loadFullWidget()
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', start)
    } else {
      start()
    }
  }

  private async loadRemoteConfig(): Promise<void> {
    if (!this.config) return

    try {
      const apiUrl = this.config.apiUrl || 'http://localhost:8000'
      const response = await fetch(`${apiUrl}/api/v1/widget/${this.config.widgetId}/config`, {
        headers: {
          'X-Widget-Host': window.location.host
        }
      })

      if (!response.ok) {
        console.warn('Synaplan Widget: Failed to load remote config')
        return
      }

      const data = await response.json()
      
      if (data.success && data.config) {
        // Merge remote config with local config
        this.config = {
          ...this.config,
          ...data.config,
          widgetTitle: data.name || this.config.widgetTitle
        }
      }
    } catch (error) {
      console.warn('Synaplan Widget: Error loading remote config', error)
    }
  }

  private getIconContent(): string {
    const iconColor = this.config?.iconColor || '#ffffff'
    
    // If custom icon URL is provided
    if (this.config?.buttonIconUrl) {
      return `<img src="${this.config.buttonIconUrl}" alt="Chat" style="width: 32px; height: 32px; object-fit: contain;" />`
    }
    
    // Predefined SVG icons
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
      </svg>`
    }
    
    const iconType = this.config?.buttonIcon || 'chat'
    return icons[iconType] || icons.chat
  }

  private createButton() {
    if (!this.config) return

    // Create button container
    this.button = document.createElement('div')
    this.button.id = 'synaplan-widget-button'
    
    const position = this.config.position || 'bottom-right'
    const positions = {
      'bottom-right': 'bottom: 20px; right: 20px;',
      'bottom-left': 'bottom: 20px; left: 20px;',
      'top-right': 'top: 20px; right: 20px;',
      'top-left': 'top: 20px; left: 20px;'
    }

    this.button.setAttribute('style', `
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
    `)

    this.button.innerHTML = this.getIconContent()

    // Hover effects
    this.button.addEventListener('mouseenter', () => {
      this.button!.style.transform = 'scale(1.1)'
      this.button!.style.boxShadow = '0 6px 20px rgba(0,0,0,0.2)'
    })

    this.button.addEventListener('mouseleave', () => {
      this.button!.style.transform = 'scale(1)'
      this.button!.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)'
    })

    // Click handler
    this.button.addEventListener('click', () => {
      this.loadFullWidget()
    })

    document.body.appendChild(this.button)
  }

  private async loadFullWidget() {
    if (this.fullWidgetLoaded || this.fullWidgetLoading) {
      return
    }

    this.fullWidgetLoading = true

    // Show loading indicator
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
      // Check if Vue is available
      if (!(window as any).Vue) {
        console.error('Synaplan Widget: Vue.js is required. Please include: <script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>')
        if (this.button) {
          this.button.innerHTML = this.getIconContent()
        }
        this.fullWidgetLoading = false
        return
      }

      console.log('Loading Synaplan Widget Full...')

      // Load the full widget script
      const script = document.createElement('script')
      const apiUrl = this.config?.apiUrl || 'http://localhost:8000'
      script.src = `${apiUrl}/widget-full.js`
      script.type = 'text/javascript'
      
      script.onload = () => {
        console.log('Synaplan Widget Full loaded, initializing...')
        
        // Remove the loader button BEFORE initializing the full widget
        if (this.button) {
          this.button.remove()
          this.button = null
        }
        
        // Wait a moment for the script to execute
        setTimeout(() => {
          const FullWidget = (window as any).SynaplanWidgetFull
          console.log('SynaplanWidgetFull available:', !!FullWidget)
          
          if (FullWidget && typeof FullWidget.init === 'function') {
            // Set openImmediately to true so the chat opens right away
            const configWithOpenImmediately = {
              ...this.config!,
              openImmediately: true
            }
            console.log('Initializing with config:', configWithOpenImmediately)
            FullWidget.init(configWithOpenImmediately)
            
            this.fullWidgetLoaded = true
            console.log('Synaplan Widget initialized successfully')
          } else {
            console.error('SynaplanWidgetFull not found or init method missing')
            // Recreate button if full widget failed
            this.fullWidgetLoading = false
            this.createButton()
          }
        }, 100)
      }

      script.onerror = (error) => {
        console.error('Failed to load Synaplan Widget:', error)
        // Restore button
        if (this.button) {
          this.button.innerHTML = this.getIconContent()
        }
        this.fullWidgetLoading = false
      }

      document.head.appendChild(script)
    } catch (error) {
      console.error('Error loading widget:', error)
      if (this.button) {
        this.button.innerHTML = this.getIconContent()
      }
      this.fullWidgetLoading = false
    }
  }

  destroy() {
    if (this.button) {
      this.button.remove()
      this.button = null
    }
  }
}

// Expose to global scope
const loaderInstance = new SynaplanWidgetLoader()

// @ts-ignore
window.SynaplanWidget = loaderInstance

export default loaderInstance

