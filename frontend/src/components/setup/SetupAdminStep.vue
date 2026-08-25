<template>
  <form class="flex flex-col gap-4" data-testid="setup-step-admin" @submit.prevent="submit">
    <div>
      <h2 class="text-xl font-bold txt-primary">{{ $t('setup.admin.title') }}</h2>
      <p class="text-sm txt-secondary mt-1">{{ $t('setup.admin.description') }}</p>
    </div>

    <label class="flex flex-col gap-1.5">
      <span class="text-sm font-medium txt-primary">{{ $t('setup.admin.emailLabel') }}</span>
      <input
        v-model="email"
        type="email"
        autocomplete="username"
        required
        :placeholder="$t('setup.admin.emailPlaceholder')"
        class="w-full px-3 py-2.5 surface-chip txt-primary placeholder:txt-secondary text-sm"
        data-testid="setup-admin-email"
      />
    </label>

    <label class="flex flex-col gap-1.5">
      <span class="text-sm font-medium txt-primary">{{ $t('setup.admin.passwordLabel') }}</span>
      <div class="relative">
        <input
          v-model="password"
          :type="showPassword ? 'text' : 'password'"
          autocomplete="new-password"
          required
          class="w-full px-3 py-2.5 pr-11 surface-chip txt-primary text-sm"
          data-testid="setup-admin-password"
        />
        <button
          type="button"
          class="absolute right-2 top-1/2 -translate-y-1/2 h-8 w-8 rounded-lg icon-ghost flex items-center justify-center"
          :aria-label="$t('setup.admin.togglePassword')"
          @click="showPassword = !showPassword"
        >
          <EyeSlashIcon v-if="showPassword" class="w-4 h-4" />
          <EyeIcon v-else class="w-4 h-4" />
        </button>
      </div>
      <span class="text-xs txt-secondary">{{ $t('setup.admin.passwordHint') }}</span>
    </label>

    <label class="flex flex-col gap-1.5">
      <span class="text-sm font-medium txt-primary">{{ $t('setup.admin.confirmLabel') }}</span>
      <input
        v-model="confirmation"
        :type="showPassword ? 'text' : 'password'"
        autocomplete="new-password"
        required
        class="w-full px-3 py-2.5 surface-chip txt-primary text-sm"
        data-testid="setup-admin-confirm"
      />
    </label>

    <p
      v-if="message"
      class="text-sm text-[var(--status-error-text)] bg-[var(--status-error-muted)] rounded-lg px-3 py-2"
      data-testid="setup-admin-error"
    >
      {{ message }}
    </p>

    <button
      type="submit"
      class="btn-primary w-full py-2.5 rounded-lg text-sm font-semibold"
      :disabled="busy"
      data-testid="setup-admin-submit"
    >
      {{ busy ? $t('setup.admin.submitting') : $t('setup.admin.submit') }}
    </button>
  </form>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/outline'
import { createFirstAdmin } from '@/services/api/setupApi'
import { getErrorMessage } from '@/utils/errorMessage'

const emit = defineEmits<{
  created: []
}>()

const { t } = useI18n()

const email = ref('')
const password = ref('')
const confirmation = ref('')
const showPassword = ref(false)
const busy = ref(false)
const serverError = ref('')
const localError = ref('')

const message = computed(() => localError.value || serverError.value)

/**
 * Mirrors the server-side rule in SetupAdminRequest so a typo costs a keystroke
 * instead of a round trip. The server stays authoritative — this only spares the
 * common mistakes.
 */
const PASSWORD_RULE = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,64}$/

async function submit(): Promise<void> {
  localError.value = ''
  serverError.value = ''

  if (password.value !== confirmation.value) {
    localError.value = t('setup.admin.errorMismatch')
    return
  }
  if (!PASSWORD_RULE.test(password.value)) {
    localError.value = t('setup.admin.errorWeak')
    return
  }

  busy.value = true
  try {
    await createFirstAdmin(email.value.trim(), password.value)
    emit('created')
  } catch (err) {
    serverError.value = getErrorMessage(err) || t('setup.admin.errorGeneric')
  } finally {
    busy.value = false
  }
}
</script>
