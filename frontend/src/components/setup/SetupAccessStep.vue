<template>
  <div class="flex flex-col gap-4" data-testid="setup-step-access">
    <div>
      <h2 class="text-xl font-bold txt-primary">{{ $t('setup.access.title') }}</h2>
      <p class="text-sm txt-secondary mt-1">{{ $t('setup.access.description') }}</p>
    </div>

    <div class="flex flex-col gap-3">
      <label
        class="surface-chip p-4 flex items-start gap-3"
        :class="registrationLocked ? 'opacity-60' : 'cursor-pointer'"
      >
        <input
          v-model="registrationEnabled"
          type="checkbox"
          class="mt-0.5 accent-[var(--brand)]"
          :disabled="registrationLocked"
          data-testid="setup-access-registration"
        />
        <span class="min-w-0">
          <span class="block text-sm font-medium txt-primary">
            {{ $t('setup.access.registrationLabel') }}
          </span>
          <span class="block text-xs txt-secondary mt-0.5">
            {{ $t('setup.access.registrationHint') }}
          </span>
          <span
            v-if="registrationLocked"
            class="block text-xs bg-[var(--status-warning-muted)] text-[var(--status-warning-text)] rounded px-2 py-1 mt-1.5"
            data-testid="setup-access-registration-locked"
          >
            {{ $t('setup.access.lockedByEnv', { key: 'REGISTRATION_ENABLED' }) }}
          </span>
          <span
            v-else-if="registrationEnabled && !mailerConfigured"
            class="block text-xs bg-[var(--status-warning-muted)] text-[var(--status-warning-text)] rounded px-2 py-1 mt-1.5"
            data-testid="setup-access-mailer-warning"
          >
            {{ $t('setup.access.noMailerWarning') }}
          </span>
        </span>
      </label>

      <label
        class="surface-chip p-4 flex items-start gap-3"
        :class="guestChatLocked ? 'opacity-60' : 'cursor-pointer'"
      >
        <input
          v-model="guestChatEnabled"
          type="checkbox"
          class="mt-0.5 accent-[var(--brand)]"
          :disabled="guestChatLocked"
          data-testid="setup-access-guest"
        />
        <span class="min-w-0">
          <span class="block text-sm font-medium txt-primary">
            {{ $t('setup.access.guestChatLabel') }}
          </span>
          <span class="block text-xs txt-secondary mt-0.5">
            {{ $t('setup.access.guestChatHint') }}
          </span>
          <span
            v-if="guestChatLocked"
            class="block text-xs bg-[var(--status-warning-muted)] text-[var(--status-warning-text)] rounded px-2 py-1 mt-1.5"
            data-testid="setup-access-guest-locked"
          >
            {{ $t('setup.access.lockedByEnv', { key: 'GUEST_CHAT_ENABLED' }) }}
          </span>
        </span>
      </label>
    </div>

    <p class="text-xs txt-secondary">{{ $t('setup.access.changeLaterHint') }}</p>

    <p
      v-if="error"
      class="text-sm text-[var(--status-error-text)] bg-[var(--status-error-muted)] rounded-lg px-3 py-2"
      data-testid="setup-access-error"
    >
      {{ error }}
    </p>

    <button
      type="button"
      class="btn-primary w-full py-2.5 rounded-lg text-sm font-semibold"
      :disabled="busy"
      data-testid="setup-access-submit"
      @click="submit"
    >
      {{ busy ? $t('setup.access.submitting') : $t('setup.access.submit') }}
    </button>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { completeSetup } from '@/services/api/setupApi'
import { getErrorMessage } from '@/utils/errorMessage'

const props = defineProps<{
  registrationLocked: boolean
  guestChatLocked: boolean
  mailerConfigured: boolean
  initialRegistrationEnabled: boolean
  initialGuestChatEnabled: boolean
}>()

const emit = defineEmits<{
  completed: []
}>()

const { t } = useI18n()

// A pinned switch is shown with the value the environment forces, so the summary
// the operator reads matches what the instance will actually do.
const registrationEnabled = ref(props.initialRegistrationEnabled)
const guestChatEnabled = ref(props.initialGuestChatEnabled)
const busy = ref(false)
const error = ref('')

async function submit(): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    await completeSetup(registrationEnabled.value, guestChatEnabled.value)
    emit('completed')
  } catch (err) {
    error.value = getErrorMessage(err) || t('setup.access.errorGeneric')
  } finally {
    busy.value = false
  }
}
</script>
