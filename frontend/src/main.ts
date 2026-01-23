import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { VueReCaptcha } from 'vue-recaptcha-v3'
import router from './router'
import { i18n } from './i18n'
import './style.css'
import './assets/markdown.css'
import App from './App.vue'
import { useConfigStore } from './stores/config'

// Bootstrap app - load config before mounting
;(async () => {
  const app = createApp(App)

  app.use(createPinia())
  app.use(router)
  app.use(i18n)

  const config = useConfigStore()
  await config.init()

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
    console.log('✅ reCAPTCHA v3 enabled')

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
    console.log('ℹ️ reCAPTCHA v3 disabled (not configured on backend)')
  }

  app.mount('#app')
})()
