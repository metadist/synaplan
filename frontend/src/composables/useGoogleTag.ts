import { onMounted, onUnmounted } from 'vue'
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
   * Inject Google Tag script into document head
   * Supports both Google Tag Manager (GTM-XXXXXXX) and Google Analytics 4 (G-XXXXXXXXXX)
   */
  const injectGoogleTag = () => {
    // Check if already injected
    if (document.getElementById('google-tag-manager') || document.getElementById('google-analytics')) {
      return
    }

    const enabled = config.googleTag.enabled
    const tagId = config.googleTag.tagId

    if (!enabled || !tagId) {
      return
    }

    // Determine if it's Google Tag Manager or Google Analytics 4
    const isGTM = tagId.startsWith('GTM-')
    const isGA4 = tagId.startsWith('G-')

    if (isGTM) {
      // Google Tag Manager
      // Script in head
      scriptElement = document.createElement('script')
      scriptElement.id = 'google-tag-manager'
      scriptElement.innerHTML = `
        (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','${tagId}');
      `
      document.head.appendChild(scriptElement)

      // Noscript in body
      noscriptElement = document.createElement('noscript')
      noscriptElement.innerHTML = `<iframe src="https://www.googletagmanager.com/ns.html?id=${tagId}" height="0" width="0" style="display:none;visibility:hidden"></iframe>`
      document.body.insertBefore(noscriptElement, document.body.firstChild)
    } else if (isGA4) {
      // Google Analytics 4
      scriptElement = document.createElement('script')
      scriptElement.id = 'google-analytics'
      scriptElement.async = true
      scriptElement.src = `https://www.googletagmanager.com/gtag/js?id=${tagId}`
      document.head.appendChild(scriptElement)

      // Initialize gtag
      const initScript = document.createElement('script')
      initScript.innerHTML = `
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '${tagId}');
      `
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
  const { injectGoogleTag, removeGoogleTag, trackEvent } = useGoogleTag()

  onMounted(() => {
    injectGoogleTag()
  })

  onUnmounted(() => {
    // Note: We typically don't remove Google Tag on unmount for auth pages
    // as the user will navigate away anyway. But we provide the option.
    // removeGoogleTag()
  })

  return {
    trackEvent,
  }
}

