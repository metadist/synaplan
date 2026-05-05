import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { VueReCaptcha } from 'vue-recaptcha-v3'
import router from './router'
import { i18n } from './i18n'
import './style.css'
import './style-v2.css'
import './assets/markdown.css'
import App from './App.vue'
import { useConfigStore } from './stores/config'
import { useGlobalErrorStore } from './stores/globalError'
import { installGlobalErrorHandlers } from './utils/installGlobalErrorHandlers'

// Bootstrap app - load config before mounting.
// We MUST install global error handlers and mount the app even when bootstrap
// fails, otherwise the user is left staring at a blank page with no recovery
// affordance. Errors raised here are pushed into the globalError store so the
// already-mounted ErrorBoundary can render the inline ErrorView immediately.
;(async () => {
  const app = createApp(App)

  app.use(createPinia())
  app.use(router)
  app.use(i18n)

  // Pinia is now installed → safe to wire global handlers that depend on stores.
  installGlobalErrorHandlers(app)

  const config = useConfigStore()

  try {
    await config.init()
  } catch (err) {
    console.error('Bootstrap failed: config.init() threw', err)
    useGlobalErrorStore().setError({
      message: err instanceof Error ? err.message : 'Failed to load runtime configuration',
      reason: 'unknown',
      source: 'bootstrap:config.init',
      stack: err instanceof Error ? (err.stack ?? '') : '',
    })
  }

  const recaptchaEnabled = config.recaptcha.enabled
  const recaptchaSiteKey = config.recaptcha.siteKey

  if (recaptchaEnabled && recaptchaSiteKey) {
    app.use(VueReCaptcha, {
      siteKey: recaptchaSiteKey,
      loaderOptions: {
        autoHideBadge: false,
        explicitRenderParameters: {
          badge: 'bottomright',
        },
      },
    })
    console.debug('reCAPTCHA v3 enabled')

    const style = document.createElement('style')
    style.id = 'recaptcha-badge-control'
    style.textContent = `
    .grecaptcha-badge {
      visibility: hidden !important;
      opacity: 0 !important;
      transition: opacity 0.3s ease !important;
    }
    .grecaptcha-badge.visible {
      visibility: visible !important;
      opacity: 1 !important;
    }
  `
    document.head.appendChild(style)
  } else {
    console.debug('reCAPTCHA v3 disabled (not configured on backend)')
  }

  app.mount('#app')
})()
