<template>
  <div id="security" class="space-y-6 scroll-mt-6">
    <section class="surface-card rounded-lg p-6" data-testid="section-change-password">
      <h2 class="text-xl font-semibold txt-primary mb-2 flex items-center gap-2">
        <Icon icon="mdi:lock" class="w-5 h-5" />
        {{ $t('profile.changePassword.title') }}
      </h2>

      <div v-if="isExternalAuth" class="mb-6 info-box-blue">
        <div class="flex items-start gap-3">
          <Icon icon="mdi:shield-check" class="w-6 h-6 info-box-blue-icon flex-shrink-0" />
          <div class="flex-1">
            <p class="text-sm info-box-blue-title mb-1">
              {{ $t('profile.personalInfo.managedBy', { provider: authProvider }) }}
            </p>
            <p class="text-sm info-box-blue-text mb-2">
              {{ $t('profile.changePassword.externalAuth', { provider: authProvider }) }}
            </p>
            <p v-if="externalAuthLastLogin" class="text-xs info-box-blue-text">
              Last authenticated: {{ externalAuthLastLogin }}
            </p>
          </div>
        </div>
      </div>

      <p v-else class="txt-secondary text-sm mb-6">
        {{ $t('profile.changePassword.subtitle') }}
      </p>

      <div v-if="canChangePassword" class="grid grid-cols-1 gap-6 max-w-2xl">
        <div data-testid="field-current-password">
          <label class="block txt-primary font-medium mb-2">
            {{ $t('profile.changePassword.currentPassword') }}
          </label>
          <input
            v-model="passwordData.current"
            type="password"
            autocomplete="current-password"
            class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
            :placeholder="$t('profile.changePassword.currentPasswordPlaceholder')"
            data-testid="input-current-password"
            @input="markPasswordTouched"
          />
        </div>

        <div data-testid="field-new-password">
          <label class="block txt-primary font-medium mb-2">
            {{ $t('profile.changePassword.newPassword') }}
          </label>
          <input
            v-model="passwordData.new"
            type="password"
            autocomplete="new-password"
            class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
            :placeholder="$t('profile.changePassword.newPasswordPlaceholder')"
            data-testid="input-new-password"
            @input="markPasswordTouched"
          />
          <p class="txt-secondary text-sm mt-1">
            {{ $t('profile.changePassword.newPasswordHint') }}
          </p>
        </div>

        <div data-testid="field-confirm-password">
          <label class="block txt-primary font-medium mb-2">
            {{ $t('profile.changePassword.confirmPassword') }}
          </label>
          <input
            v-model="passwordData.confirm"
            type="password"
            autocomplete="new-password"
            class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
            :placeholder="$t('profile.changePassword.confirmPasswordPlaceholder')"
            data-testid="input-confirm-password"
            @input="markPasswordTouched"
          />
          <p class="txt-secondary text-sm mt-1">
            {{ $t('profile.changePassword.confirmPasswordHint') }}
          </p>
        </div>
      </div>
    </section>

    <section
      v-if="showBiometricSection"
      class="surface-card rounded-lg p-6"
      data-testid="section-app-security"
    >
      <h2 class="text-xl font-semibold txt-primary mb-2 flex items-center gap-2">
        <Icon icon="mdi:fingerprint" class="w-5 h-5" />
        {{ $t('profile.appSecurity.title') }}
      </h2>
      <p class="txt-secondary text-sm mb-6">{{ $t('profile.appSecurity.subtitle') }}</p>

      <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
          <p class="txt-primary font-medium">
            {{ $t('profile.appSecurity.biometricLock') }}
          </p>
          <p class="txt-secondary text-sm">
            {{ $t('profile.appSecurity.biometricLockHint') }}
          </p>
        </div>
        <button
          type="button"
          role="switch"
          :aria-checked="biometricLockOn"
          class="relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :class="
            biometricLockOn ? 'bg-[var(--brand)]' : 'bg-light-border/50 dark:bg-dark-border/50'
          "
          data-testid="toggle-biometric-lock"
          @click="toggleBiometricLock"
        >
          <span
            class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
            :class="biometricLockOn ? 'translate-x-6' : 'translate-x-1'"
          />
        </button>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { injectProfileSettings } from '@/composables/useProfileSettings'

const {
  passwordData,
  canChangePassword,
  isExternalAuth,
  authProvider,
  externalAuthLastLogin,
  markPasswordTouched,
  showBiometricSection,
  biometricLockOn,
  toggleBiometricLock,
} = injectProfileSettings()
</script>
