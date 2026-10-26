<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'

export interface AdmissionPlan {
  id: string
  name: string
  priceLabel: string
}

const props = defineProps<{
  plans: AdmissionPlan[]
  native: boolean
  initialPlan: string | null
}>()

const emit = defineEmits<{
  continue: [choice: { plan: string | null; code: string }]
}>()

const { t } = useI18n()
const selectedPlan = ref<string | null>(props.native ? null : props.initialPlan)
const code = ref('')

watch(code, (value) => {
  if (value.trim() !== '') selectedPlan.value = null
})

const canContinue = computed(() => selectedPlan.value !== null || code.value.trim() !== '')

function pickPlan(id: string) {
  selectedPlan.value = id
  code.value = ''
}

function submit() {
  if (!canContinue.value) return
  emit('continue', {
    plan: selectedPlan.value,
    code: code.value.trim(),
  })
}
</script>

<template>
  <div data-testid="section-admission">
    <p v-if="native" class="text-sm txt-secondary mb-4 leading-relaxed">
      {{ t('auth.admission.nativeNote') }}
    </p>

    <div
      v-else-if="plans.length > 0"
      role="radiogroup"
      :aria-label="t('auth.admission.plansLabel')"
      class="space-y-2"
    >
      <button
        v-for="plan in plans"
        :key="plan.id"
        type="button"
        role="radio"
        :aria-checked="selectedPlan === plan.id"
        class="flex w-full items-center justify-between gap-3 rounded-xl px-3.5 py-2.5 text-left text-sm font-medium surface-chip txt-primary transition-all duration-200 active:scale-[0.97]"
        :class="
          selectedPlan === plan.id
            ? 'ring-2 ring-brand'
            : 'ring-1 ring-black/[0.06] dark:ring-white/[0.06] hover:ring-brand/30'
        "
        :data-testid="`plan-${plan.id}`"
        @click="pickPlan(plan.id)"
      >
        <span class="min-w-0 truncate">{{ plan.name }}</span>
        <span v-if="plan.priceLabel" class="shrink-0 text-xs font-normal txt-secondary">
          {{ plan.priceLabel }}
        </span>
      </button>
    </div>

    <p v-if="!native && plans.length > 0" class="my-4 text-center text-xs txt-secondary">
      {{ t('auth.admission.orCode') }}
    </p>

    <div class="relative">
      <Icon
        icon="mdi:ticket-confirmation-outline"
        class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4.5 h-4.5 pointer-events-none txt-secondary"
      />
      <input
        v-model="code"
        type="text"
        autocomplete="off"
        spellcheck="false"
        class="auth-input pl-10 surface-chip txt-primary placeholder:txt-secondary w-full"
        :placeholder="t('auth.admission.codePlaceholder')"
        :aria-label="t('auth.admission.codeLabel')"
        data-testid="input-invitation-code"
      />
    </div>
    <p class="text-xs txt-secondary mt-2 pl-1">{{ t('auth.admission.codeHint') }}</p>

    <button
      type="button"
      class="auth-submit-btn btn-primary mt-4"
      :disabled="!canContinue"
      data-testid="btn-admission-continue"
      @click="submit"
    >
      {{ t('auth.admission.continue') }}
    </button>
  </div>
</template>

<style scoped>
.auth-input {
  width: 100%;
  padding-top: 0.75rem;
  padding-bottom: 0.75rem;
  border-radius: 0.75rem;
  font-size: 0.875rem;
  line-height: 1.25rem;
  border: 0;
}
.auth-input:focus {
  outline: none;
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand) 40%, transparent);
}
.auth-submit-btn {
  width: 100%;
  padding: 0.75rem;
  border-radius: 0.75rem;
  font-weight: 600;
  font-size: 0.875rem;
}
.auth-submit-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
