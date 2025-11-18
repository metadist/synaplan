import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { VueReCaptcha } from 'vue-recaptcha-v3'
import router from './router'
import { i18n } from './i18n'
import './style.css'
import App from './App.vue'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(i18n)

// Google reCAPTCHA v3 (only if enabled in production)
const recaptchaEnabled = import.meta.env.VITE_RECAPTCHA_ENABLED === 'true'
const recaptchaSiteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY

if (recaptchaEnabled && recaptchaSiteKey && recaptchaSiteKey !== 'your_site_key_here') {
  app.use(VueReCaptcha, { 
    siteKey: recaptchaSiteKey,
    loaderOptions: {
      autoHideBadge: false,
      explicitRenderParameters: {
        badge: 'bottomright'
      }
    }
  })
  console.log('✅ reCAPTCHA v3 enabled')
} else {
  console.log('ℹ️ reCAPTCHA v3 disabled (dev mode)')
}

app.mount('#app')
