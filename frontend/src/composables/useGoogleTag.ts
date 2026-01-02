import { onMounted } from 'vue'
import { useConfigStore } from '@/stores/config'

/**
 * Google Tag Manager / Google Analytics integration
 * Conditionally injects Google Tag script only if enabled and configured
 */
export const useGoogleTag = () => {
  const config = useConfigStore()
  let scriptElement: HTMLScriptElement | null = null
  let noscriptElement: HTMLElement | null = null

  /**
   * Sanitize tag ID to prevent XSS
   * Only allows alphanumeric characters, dash, and underscore
   * Valid formats: GTM-XXXXXXX or G-XXXXXXXXXX
   */
  const sanitizeTagId = (tagId: string): string => {
    // Validate format: GTM- followed by alphanumeric, or G- followed by alphanumeric
    const validPattern = /^(GTM-[A-Z0-9]+|G-[A-Z0-9]+)$/i
    if (validPattern.test(tagId)) {
      return tagId
    }
    return ''
  }

  /**
   * Inject Google Tag script into document head
   * Supports both Google Tag Manager (GTM-XXXXXXX) and Google Analytics 4 (G-XXXXXXXXXX)
   */
  const injectGoogleTag = () => {
    // Check if already injected
    if (document.getElementById('google-tag-manager') || document.getElementById('google-analytics')) {
      return
    }

    const enabled = config.googleTag.enabled
    const tagIdRaw = config.googleTag.tagId

    if (!enabled || !tagIdRaw) {
      return
    }

    // Sanitize tag ID to prevent XSS
    const tagId = sanitizeTagId(tagIdRaw)
    if (!tagId) {
      console.warn('Invalid Google Tag ID format, skipping injection')
      return
    }

    // Determine if it's Google Tag Manager or Google Analytics 4
    const isGTM = tagId.startsWith('GTM-')
    const isGA4 = tagId.startsWith('G-')

    if (isGTM) {
      // Google Tag Manager
      // Script in head - use textContent and create text node to avoid XSS
      scriptElement = document.createElement('script')
      scriptElement.id = 'google-tag-manager'
      const scriptText = document.createTextNode(
        `(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','${tagId}');`
      )
      scriptElement.appendChild(scriptText)
      document.head.appendChild(scriptElement)

      // Noscript in body - use createElement for iframe to avoid innerHTML XSS
      noscriptElement = document.createElement('noscript')
      const iframe = document.createElement('iframe')
      iframe.src = `https://www.googletagmanager.com/ns.html?id=${tagId}`
      iframe.height = '0'
      iframe.width = '0'
      iframe.style.display = 'none'
      iframe.style.visibility = 'hidden'
      noscriptElement.appendChild(iframe)
      document.body.insertBefore(noscriptElement, document.body.firstChild)
    } else if (isGA4) {
      // Google Analytics 4
      scriptElement = document.createElement('script')
      scriptElement.id = 'google-analytics'
      scriptElement.async = true
      scriptElement.src = `https://www.googletagmanager.com/gtag/js?id=${tagId}`
      document.head.appendChild(scriptElement)

      // Initialize gtag - use textContent to avoid XSS
      const initScript = document.createElement('script')
      const initText = document.createTextNode(
        `window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}gtag('js', new Date());gtag('config', '${tagId}');`
      )
      initScript.appendChild(initText)
      document.head.appendChild(initScript)
    }
  }

  /**
   * Remove Google Tag script from document
   */
  const removeGoogleTag = () => {
    if (scriptElement && scriptElement.parentNode) {
      scriptElement.parentNode.removeChild(scriptElement)
      scriptElement = null
    }

    if (noscriptElement && noscriptElement.parentNode) {
      noscriptElement.parentNode.removeChild(noscriptElement)
      noscriptElement = null
    }

    // Also remove any dynamically added scripts
    const gtmScript = document.getElementById('google-tag-manager')
    if (gtmScript && gtmScript.parentNode) {
      gtmScript.parentNode.removeChild(gtmScript)
    }

    const gaScript = document.getElementById('google-analytics')
    if (gaScript && gaScript.parentNode) {
      gaScript.parentNode.removeChild(gaScript)
    }

    // Remove gtag initialization script
    const tagId = config.googleTag.tagId
    if (tagId) {
      const scripts = document.head.querySelectorAll('script')
      scripts.forEach((script) => {
        if (script.innerHTML.includes('gtag(') && script.innerHTML.includes(tagId)) {
          script.parentNode?.removeChild(script)
        }
      })
    }
  }

  /**
   * Track an event (only if Google Tag is enabled)
   */
  const trackEvent = (eventName: string, eventParams?: Record<string, unknown>) => {
    if (!config.googleTag.enabled || !config.googleTag.tagId) {
      return
    }

    // Check if gtag is available (GA4) or dataLayer (GTM)
    if (typeof window !== 'undefined') {
      if (typeof (window as unknown as { gtag?: unknown }).gtag === 'function') {
        // Google Analytics 4
        ;(window as unknown as { gtag: (command: string, targetId: string, config?: Record<string, unknown>) => void }).gtag('event', eventName, eventParams)
      } else if (Array.isArray((window as unknown as { dataLayer?: unknown[] }).dataLayer)) {
        // Google Tag Manager
        ;(window as unknown as { dataLayer: unknown[] }).dataLayer.push({
          event: eventName,
          ...eventParams,
        })
      }
    }
  }

  return {
    injectGoogleTag,
    removeGoogleTag,
    trackEvent,
  }
}

/**
 * Composable to automatically inject Google Tag on component mount
 * Use this in LoginView and RegisterView
 */
export const useGoogleTagAuto = () => {
  const { injectGoogleTag, trackEvent } = useGoogleTag()

  onMounted(() => {
    injectGoogleTag()
  })

  return {
    trackEvent,
  }
}

