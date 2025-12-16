import { ref } from 'vue'

interface LimitCheckResult {
  allowed: boolean
  limitType: 'lifetime' | 'hourly' | 'monthly'
  actionType: string
  used: number
  limit: number
  remaining: number
  resetTime?: number | null
  userLevel: string
  phoneVerified: boolean
}

export function useLimitCheck() {
  const showLimitModal = ref(false)
  const limitData = ref<LimitCheckResult | null>(null)

  const checkAndShowLimit = (result: LimitCheckResult) => {
    if (!result.allowed) {
      limitData.value = result
      showLimitModal.value = true
      return false
    }
    return true
  }

  const closeLimitModal = () => {
    showLimitModal.value = false
    limitData.value = null
  }

  return {
    showLimitModal,
    limitData,
    checkAndShowLimit,
    closeLimitModal,
  }
}
