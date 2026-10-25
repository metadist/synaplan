import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import type { AdmissionPlan } from '@/components/auth/RegisterAdmissionStep.vue'
import { isNativeApp } from '@/services/api/nativeRuntime'
import { subscriptionApi } from '@/services/api/subscriptionApi'
import { useConfigStore } from '@/stores/config'

const PAID_PLANS = ['PRO', 'TEAM', 'BUSINESS']

/**
 * The plan-or-invitation step shown before the register form when this
 * install is in admission mode. Open mode never shows it. The native app
 * asks for a code only: paid plans are bought through the store, not here.
 */
export function useRegisterAdmission() {
  const config = useConfigStore()
  const route = useRoute()
  const { locale } = useI18n()

  const admissionMode = computed(() => config.auth.registrationMode === 'admission')
  const admissionChoice = ref<{ plan: string | null; code: string } | null>(null)
  const admissionPlans = ref<AdmissionPlan[]>([])

  const showIdentity = computed(() => !admissionMode.value || admissionChoice.value !== null)
  const initialPlan = computed(() => {
    if (isNativeApp()) return null
    const plan = String(route.query.plan || '').toUpperCase()
    return PAID_PLANS.includes(plan) ? plan : null
  })
  const admissionChoiceLabel = computed(() => {
    const choice = admissionChoice.value
    if (!choice) return ''
    if (choice.code) return choice.code
    return admissionPlans.value.find((plan) => plan.id === choice.plan)?.name || choice.plan || ''
  })

  function onAdmissionContinue(choice: { plan: string | null; code: string }) {
    admissionChoice.value = choice.plan
      ? { plan: choice.plan, code: '' }
      : { plan: null, code: choice.code }
  }

  async function loadAdmissionPlans(): Promise<void> {
    if (!admissionMode.value || isNativeApp()) return
    try {
      const response = await subscriptionApi.getPlans()
      if (!response.stripeConfigured) return
      admissionPlans.value = response.plans
        .filter((plan) => PAID_PLANS.includes(plan.id))
        .map((plan) => ({
          id: plan.id,
          name: plan.name,
          priceLabel: new Intl.NumberFormat(String(locale.value), {
            style: 'currency',
            currency: plan.currency || 'EUR',
          }).format(plan.price),
        }))
    } catch {
      admissionPlans.value = []
    }
  }

  return {
    admissionMode,
    admissionChoice,
    admissionPlans,
    showIdentity,
    initialPlan,
    admissionChoiceLabel,
    onAdmissionContinue,
    loadAdmissionPlans,
  }
}
