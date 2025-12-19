import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { VueReCaptcha } from 'vue-recaptcha-v3'
import router from './router'
import { i18n } from './i18n'
import './style.css'
import App from './App.vue'
import { useConfigStore } from './stores/config'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(i18n)

// Load runtime configuration from backend, then initialize reCAPTCHA
const config = useConfigStore()
config.init().then(() => {
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

    // Hide badge by default (only show on auth pages - controlled by App.vue)
    // Using CSS instead of autoHideBadge to allow dynamic control
    const style = document.createElement('style')
    style.id = 'recaptcha-badge-control'
    // Use class-based visibility that can be overridden
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
})
