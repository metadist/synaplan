<template>
  <section class="surface-card rounded-lg p-6" data-testid="section-personal">
    <h2 class="text-xl font-semibold txt-primary mb-6 flex items-center gap-2">
      <Icon icon="mdi:account" class="w-5 h-5" />
      {{ $t('profile.personalInfo.title') }}
    </h2>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <div data-testid="field-first-name">
        <label class="block txt-primary font-medium mb-2">
          {{ $t('profile.personalInfo.firstName') }}
        </label>
        <input
          v-model="formData.firstName"
          type="text"
          class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
          :placeholder="$t('profile.personalInfo.firstNamePlaceholder')"
          data-testid="input-first-name"
        />
      </div>

      <div data-testid="field-last-name">
        <label class="block txt-primary font-medium mb-2">
          {{ $t('profile.personalInfo.lastName') }}
        </label>
        <input
          v-model="formData.lastName"
          type="text"
          class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
          :placeholder="$t('profile.personalInfo.lastNamePlaceholder')"
          data-testid="input-last-name"
        />
      </div>

      <div data-testid="field-email">
        <label class="block txt-primary font-medium mb-2 flex items-center gap-2">
          {{ $t('profile.personalInfo.email') }}
          <span
            v-if="isExternalAuth"
            class="px-2 py-0.5 rounded text-xs font-semibold bg-gradient-to-br from-blue-500 to-blue-600 text-white shadow-sm"
          >
            {{ authProvider }}
          </span>
        </label>
        <input
          v-model="formData.email"
          type="email"
          autocomplete="email"
          :disabled="!canChangeEmail"
          :class="emailInputClass"
          data-testid="input-email"
        />
        <p class="text-sm txt-secondary mt-1">
          {{ emailFieldHint }}
        </p>
        <div v-if="canChangeEmail && emailChanged" class="mt-4" data-testid="field-email-password">
          <label class="block txt-primary font-medium mb-2" for="profile-email-password">
            {{ $t('profile.personalInfo.emailPasswordLabel') }}
          </label>
          <input
            id="profile-email-password"
            v-model="emailPassword"
            type="password"
            autocomplete="current-password"
            class="w-full px-4 py-2.5 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :placeholder="$t('profile.personalInfo.emailPasswordPlaceholder')"
            data-testid="input-email-password"
          />
          <p class="text-sm txt-secondary mt-1">
            {{ $t('profile.personalInfo.emailPasswordHint') }}
          </p>
        </div>
      </div>

      <div data-testid="field-phone">
        <label class="block txt-primary font-medium mb-2">
          {{ $t('profile.personalInfo.phone') }}
        </label>
        <input
          v-model="formData.phone"
          type="tel"
          class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
          :placeholder="$t('profile.personalInfo.phonePlaceholder')"
          data-testid="input-phone"
        />
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { injectProfileSettings } from '@/composables/useProfileSettings'

const {
  formData,
  isExternalAuth,
  authProvider,
  canChangeEmail,
  emailChanged,
  emailPassword,
  emailFieldHint,
  emailInputClass,
} = injectProfileSettings()
</script>
