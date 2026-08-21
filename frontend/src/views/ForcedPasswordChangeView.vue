<template>
  <div
    class="min-h-screen bg-light-bg dark:bg-dark-bg flex items-center justify-center px-4 py-12"
    data-testid="page-forced-password-change"
  >
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <img :src="logoSrc" :alt="brandName" class="h-12 mx-auto mb-6" />
        <h1 class="text-3xl font-bold txt-primary mb-2">
          {{ $t('forcedPasswordChange.title') }}
        </h1>
        <p class="txt-secondary">{{ $t('forcedPasswordChange.subtitle') }}</p>
      </div>

      <div class="surface-card p-8">
        <div class="info-box-blue mb-6">
          <div class="flex items-start gap-3">
            <Icon icon="mdi:shield-key" class="w-6 h-6 info-box-blue-icon flex-shrink-0" />
            <p class="text-sm info-box-blue-text">
              {{ $t('forcedPasswordChange.explanation', { email: userEmail }) }}
            </p>
          </div>
        </div>

        <form class="space-y-5" @submit.prevent="handleSubmit">
          <div>
            <label for="current-password" class="block text-sm font-medium txt-primary mb-2">
              {{ $t('forcedPasswordChange.currentPassword') }}
            </label>
            <input
              id="current-password"
              v-model="currentPassword"
              type="password"
              required
              autocomplete="current-password"
              class="w-full px-4 py-3 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors border-0"
              :placeholder="$t('forcedPasswordChange.currentPasswordPlaceholder')"
              data-testid="input-current-password"
            />
          </div>

          <div>
            <label for="new-password" class="block text-sm font-medium txt-primary mb-2">
              {{ $t('forcedPasswordChange.newPassword') }}
            </label>
            <input
              id="new-password"
              v-model="newPassword"
              type="password"
              required
              autocomplete="new-password"
              class="w-full px-4 py-3 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors border-0"
              :class="{ 'ring-2 ring-red-500': passwordErrors.length > 0 }"
              :placeholder="$t('forcedPasswordChange.newPasswordPlaceholder')"
              data-testid="input-new-password"
            />
            <div v-if="passwordErrors.length > 0" class="mt-2 space-y-1">
              <p
                v-for="err in passwordErrors"
                :key="err"
                class="text-xs text-red-600 dark:text-red-400"
              >
                • {{ err }}
              </p>
            </div>
          </div>

          <div>
            <label for="confirm-password" class="block text-sm font-medium txt-primary mb-2">
              {{ $t('forcedPasswordChange.confirmPassword') }}
            </label>
            <input
              id="confirm-password"
              v-model="confirmPassword"
              type="password"
              required
              autocomplete="new-password"
              class="w-full px-4 py-3 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-colors border-0"
              :placeholder="$t('forcedPasswordChange.confirmPasswordPlaceholder')"
              data-testid="input-confirm-password"
            />
            <p
              v-if="newPassword && confirmPassword && newPassword !== confirmPassword"
              class="text-sm text-yellow-600 dark:text-yellow-400 mt-1"
              data-testid="text-password-mismatch"
            >
              {{ $t('forcedPasswordChange.mismatch') }}
            </p>
          </div>

          <div v-if="errorMessage" class="alert-error" data-testid="alert-change-error">
            <p class="text-sm alert-error-text">{{ errorMessage }}</p>
          </div>

          <Button
            type="submit"
            class="w-full btn-primary py-3 rounded-lg font-medium"
            :disabled="!canSubmit"
            data-testid="btn-change-password"
          >
            {{ loading ? $t('forcedPasswordChange.saving') : $t('forcedPasswordChange.submit') }}
          </Button>
        </form>

        <button
          type="button"
          class="w-full mt-4 text-sm txt-secondary hover:txt-primary transition-colors"
          data-testid="btn-logout"
          @click="handleLogout"
        >
          {{ $t('forcedPasswordChange.logout') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import Button from '@/components/Button.vue'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'
import { useTheme } from '@/composables/useTheme'
import { useBrandLogo } from '@/composables/useBrandLogo'
import { usePasswordValidation } from '@/composables/usePasswordValidation'
import { useNotification } from '@/composables/useNotification'
import { profileApi } from '@/services/api'
import { getApiErrorMessage } from '@/utils/errorMessage'

const router = useRouter()
const { t } = useI18n()
const authStore = useAuthStore()
const configStore = useConfigStore()
const themeStore = useTheme()
const { success } = useNotification()

const isDark = computed(() => {
  if ('dark' === themeStore.theme.value) return true
  if ('light' === themeStore.theme.value) return false
  return matchMedia('(prefers-color-scheme: dark)').matches
})
const { logoSrc } = useBrandLogo(isDark)

const brandName = computed(() => configStore.branding.name)
const userEmail = computed(() => authStore.user?.email ?? '')

const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const passwordErrors = ref<string[]>([])
const errorMessage = ref('')
const loading = ref(false)

const canSubmit = computed(
  () =>
    !loading.value &&
    '' !== currentPassword.value &&
    '' !== newPassword.value &&
    newPassword.value === confirmPassword.value
)

const handleSubmit = async () => {
  errorMessage.value = ''
  passwordErrors.value = []

  const validation = usePasswordValidation(newPassword.value)
  if (!validation.isValid) {
    passwordErrors.value = validation.errors
    return
  }

  loading.value = true
  try {
    await profileApi.changePassword(currentPassword.value, newPassword.value)
    // Clears mustChangePassword, which is what unlocks the rest of the router.
    await authStore.refreshUser()
    success(t('forcedPasswordChange.done'))
    await router.replace('/')
  } catch (err: unknown) {
    errorMessage.value = getApiErrorMessage(err) || t('forcedPasswordChange.failed')
  } finally {
    loading.value = false
  }
}

const handleLogout = async () => {
  await authStore.logout()
  await router.replace('/login')
}
</script>
