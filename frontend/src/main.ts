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

// Google reCAPTCHA v3 (only if enabled)
// Uses runtime config (injected at container startup) with build-time fallback
const config = useConfigStore()
const recaptchaEnabled = config.recaptcha.enabled
const recaptchaSiteKey = config.recaptcha.siteKey

if (recaptchaEnabled && recaptchaSiteKey && recaptchaSiteKey !== 'your_site_key_here') {
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
} else {
  console.log('ℹ️ reCAPTCHA v3 disabled (dev mode or not configured)')
}

app.mount('#app')
