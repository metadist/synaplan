import { useReCaptcha } from 'vue-recaptcha-v3'

export const useRecaptcha = () => {
  const recaptchaEnabled = import.meta.env.VITE_RECAPTCHA_ENABLED === 'true'
  const recaptchaSiteKey = import.meta.env.VITE_RECAPTCHA_SITE_KEY

  // Only use reCAPTCHA instance if it's enabled and configured
  const recaptchaInstance =
    recaptchaEnabled && recaptchaSiteKey && recaptchaSiteKey !== 'your_site_key_here'
      ? useReCaptcha()
      : null

  /**
   * Get reCAPTCHA token for action
   * Returns empty string if reCAPTCHA is disabled (dev mode)
   */
  const getToken = async (action: string): Promise<string> => {
    if (!recaptchaEnabled || !recaptchaInstance) {
      console.log(`ℹ️ reCAPTCHA disabled - skipping token generation for action: ${action}`)
      return ''
    }

    try {
      await recaptchaInstance.recaptchaLoaded()
      const token = await recaptchaInstance.executeRecaptcha(action)
      console.log(`✅ reCAPTCHA token generated for action: ${action}`)
      return token
    } catch (error) {
      console.error('❌ Failed to get reCAPTCHA token:', error)
      // Return empty string on error to allow request to proceed
      // Backend will handle verification failure appropriately
      return ''
    }
  }

  return {
    isEnabled: recaptchaEnabled,
    getToken,
  }
}
