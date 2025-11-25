<template>
  <MainLayout data-testid="page-profile">
    <div class="h-full overflow-y-auto scroll-thin">
      <div class="max-w-4xl mx-auto p-4 md:p-8">
        <div class="mb-8" data-testid="section-header">
          <h1 class="text-3xl font-bold txt-primary mb-2">{{ $t('profile.title') }}</h1>
          <p class="txt-secondary">{{ $t('profile.subtitle') }}</p>
        </div>

        <form @submit.prevent="handleSave" class="space-y-6" data-testid="comp-profile-form">
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
                  disabled
                  class="w-full px-4 py-2.5 rounded-lg bg-chat/50 border border-light-border/30 dark:border-dark-border/20 txt-secondary cursor-not-allowed"
                  :title="$t('profile.personalInfo.emailHint')"
                  data-testid="input-email"
                />
                <p v-if="isExternalAuth" class="text-xs txt-secondary mt-1">
                  This account is managed by {{ authProvider }}
                </p>
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

          <section class="surface-card rounded-lg p-6" data-testid="section-company">
            <h2 class="text-xl font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="mdi:office-building" class="w-5 h-5" />
              {{ $t('profile.companyInfo.title') }}
            </h2>
            <p class="txt-secondary text-sm mb-6">{{ $t('profile.companyInfo.subtitle') }}</p>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div data-testid="field-company-name">
                <label class="block txt-primary font-medium mb-2">
                  {{ $t('profile.companyInfo.companyName') }}
                </label>
                <input
                  v-model="formData.companyName"
                  type="text"
                  class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  :placeholder="$t('profile.companyInfo.companyNamePlaceholder')"
                  data-testid="input-company-name"
                />
              </div>

              <div data-testid="field-vat-id">
                <label class="block txt-primary font-medium mb-2">
                  {{ $t('profile.companyInfo.vatId') }}
                </label>
                <input
                  v-model="formData.vatId"
                  type="text"
                  class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  :placeholder="$t('profile.companyInfo.vatIdPlaceholder')"
                  data-testid="input-vat-id"
                />
              </div>
            </div>
          </section>

          <section class="surface-card rounded-lg p-6" data-testid="section-billing">
            <h2 class="text-xl font-semibold txt-primary mb-6 flex items-center gap-2">
              <Icon icon="mdi:map-marker" class="w-5 h-5" />
              {{ $t('profile.billingAddress.title') }}
            </h2>
            
            <div class="grid grid-cols-1 gap-6" data-testid="group-address">
              <div data-testid="field-street">
                <label class="block txt-primary font-medium mb-2">
                  {{ $t('profile.billingAddress.street') }}
                </label>
                <input
                  v-model="formData.street"
                  type="text"
                  class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  :placeholder="$t('profile.billingAddress.streetPlaceholder')"
                  data-testid="input-street"
                />
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div data-testid="field-zip">
                  <label class="block txt-primary font-medium mb-2">
                    {{ $t('profile.billingAddress.zipCode') }}
                  </label>
                  <input
                    v-model="formData.zipCode"
                    type="text"
                    class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                    :placeholder="$t('profile.billingAddress.zipCodePlaceholder')"
                    data-testid="input-zip"
                  />
                </div>

                <div class="md:col-span-2" data-testid="field-city">
                  <label class="block txt-primary font-medium mb-2">
                    {{ $t('profile.billingAddress.city') }}
                  </label>
                  <input
                    v-model="formData.city"
                    type="text"
                    class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                    :placeholder="$t('profile.billingAddress.cityPlaceholder')"
                    data-testid="input-city"
                  />
                </div>
              </div>

              <div data-testid="field-country">
                <label class="block txt-primary font-medium mb-2">
                  {{ $t('profile.billingAddress.country') }}
                </label>
                <select
                  v-model="formData.country"
                  class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  data-testid="select-country"
                >
                  <option v-for="country in countries" :key="country.code" :value="country.code">
                    {{ country.name }}
                  </option>
                </select>
              </div>
            </div>
          </section>

          <section class="surface-card rounded-lg p-6" data-testid="section-account-settings">
            <h2 class="text-xl font-semibold txt-primary mb-6 flex items-center gap-2">
              <Icon icon="mdi:cog" class="w-5 h-5" />
              {{ $t('profile.accountSettings.title') }}
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div data-testid="field-language">
                <label class="block txt-primary font-medium mb-2">
                  {{ $t('profile.accountSettings.language') }}
                </label>
                <select
                  v-model="formData.language"
                  class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  data-testid="select-language"
                >
                  <option v-for="lang in languages" :key="lang.code" :value="lang.code">
                    {{ lang.name }}
                  </option>
                </select>
              </div>

              <div data-testid="field-timezone">
                <label class="block txt-primary font-medium mb-2">
                  {{ $t('profile.accountSettings.timezone') }}
                </label>
                <select
                  v-model="formData.timezone"
                  class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  data-testid="select-timezone"
                >
                  <option v-for="tz in timezones" :key="tz.value" :value="tz.value">
                    {{ tz.label }}
                  </option>
                </select>
              </div>

              <div class="md:col-span-2" data-testid="field-invoice-email">
                <label class="block txt-primary font-medium mb-2">
                  {{ $t('profile.accountSettings.invoiceEmail') }}
                </label>
                <input
                  v-model="formData.invoiceEmail"
                  type="email"
                  class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  :placeholder="$t('profile.accountSettings.invoiceEmailPlaceholder')"
                  data-testid="input-invoice-email"
                />
              </div>
            </div>
          </section>

          <section class="surface-card rounded-lg p-6" data-testid="section-change-password">
            <h2 class="text-xl font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="mdi:lock" class="w-5 h-5" />
              {{ $t('profile.changePassword.title') }}
            </h2>
            
            <!-- External Auth Warning -->
            <div v-if="isExternalAuth" class="mb-6 p-4 rounded-lg bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900/20 dark:to-blue-900/30 border border-blue-300 dark:border-blue-700">
              <div class="flex items-start gap-3">
                <Icon icon="mdi:shield-check" class="w-6 h-6 text-blue-600 dark:text-blue-400 flex-shrink-0" />
                <div class="flex-1">
                  <p class="text-sm font-semibold text-blue-900 dark:text-blue-100 mb-1">
                    🔒 Managed by {{ authProvider }}
                  </p>
                  <p class="text-sm text-blue-700 dark:text-blue-300 mb-2">
                    You're using {{ authProvider }} to sign in. Password management is handled through {{ authProvider }}.
                  </p>
                  <p v-if="externalAuthLastLogin" class="text-xs text-blue-600 dark:text-blue-400">
                    Last authenticated: {{ externalAuthLastLogin }}
                  </p>
                </div>
              </div>
            </div>
            
            <p v-else class="txt-secondary text-sm mb-6">{{ $t('profile.changePassword.subtitle') }}</p>
            
            <div v-if="canChangePassword" class="grid grid-cols-1 gap-6 max-w-2xl">
              <div data-testid="field-current-password">
                <label class="block txt-primary font-medium mb-2">
                  {{ $t('profile.changePassword.currentPassword') }}
                </label>
                <input
                  v-model="passwordData.current"
                  type="password"
                  class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  :placeholder="$t('profile.changePassword.currentPasswordPlaceholder')"
                  data-testid="input-current-password"
                />
              </div>

              <div data-testid="field-new-password">
                <label class="block txt-primary font-medium mb-2">
                  {{ $t('profile.changePassword.newPassword') }}
                </label>
                <input
                  v-model="passwordData.new"
                  type="password"
                  class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  :placeholder="$t('profile.changePassword.newPasswordPlaceholder')"
                  data-testid="input-new-password"
                />
                <p class="txt-secondary text-sm mt-1">{{ $t('profile.changePassword.newPasswordHint') }}</p>
              </div>

              <div data-testid="field-confirm-password">
                <label class="block txt-primary font-medium mb-2">
                  {{ $t('profile.changePassword.confirmPassword') }}
                </label>
                <input
                  v-model="passwordData.confirm"
                  type="password"
                  class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  :placeholder="$t('profile.changePassword.confirmPasswordPlaceholder')"
                  data-testid="input-confirm-password"
                />
                <p class="txt-secondary text-sm mt-1">{{ $t('profile.changePassword.confirmPasswordHint') }}</p>
              </div>
            </div>
          </section>

          <div class="surface-card rounded-lg p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800" data-testid="section-privacy-notice">
            <p class="txt-secondary text-sm flex items-start gap-2">
              <Icon icon="mdi:information" class="w-5 h-5 flex-shrink-0 mt-0.5" />
              <span>{{ $t('profile.privacyNotice') }}</span>
            </p>
          </div>

          <div class="h-20"></div>
        </form>
      </div>
    </div>

    <UnsavedChangesBar
      :show="hasUnsavedChanges"
      @save="handleSave"
      @discard="handleDiscard"
    />
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import UnsavedChangesBar from '@/components/UnsavedChangesBar.vue'
import { countries, languages, timezones, type UserProfile } from '@/mocks/profile'
import { useNotification } from '@/composables/useNotification'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { profileApi } from '@/services/api'

const { error, success } = useNotification()

const formData = ref<UserProfile>({
  email: '',
  firstName: '',
  lastName: '',
  phone: '',
  companyName: '',
  vatId: '',
  street: '',
  zipCode: '',
  city: '',
  country: 'DE',
  language: 'en',
  timezone: 'Europe/Berlin',
  invoiceEmail: ''
})
const originalData = ref<UserProfile>({ ...formData.value })
const passwordData = ref({
  current: '',
  new: '',
  confirm: '',
})
const loading = ref(false)
const canChangePassword = ref(true)
const authProvider = ref<string>('Email/Password')
const isExternalAuth = ref(false)
const externalAuthLastLogin = ref<string | null>(null)

const { hasUnsavedChanges, saveChanges, discardChanges, setupNavigationGuard } = useUnsavedChanges(
  formData,
  originalData
)

let cleanupGuard: (() => void) | undefined

onMounted(async () => {
  cleanupGuard = setupNavigationGuard()
  
  // Load profile from backend
  try {
    loading.value = true
    const response = await profileApi.getProfile()
    if (response.success && response.profile) {
      Object.assign(formData.value, response.profile)
      originalData.value = { ...formData.value }
      
      // Set auth info
      canChangePassword.value = response.profile.canChangePassword ?? true
      authProvider.value = response.profile.authProvider ?? 'Email/Password'
      isExternalAuth.value = response.profile.isExternalAuth ?? false
      externalAuthLastLogin.value = response.profile.externalAuthInfo?.lastLogin ?? null
    }
  } catch (err: any) {
    error(err.message || 'Failed to load profile')
  } finally {
    loading.value = false
  }
})

onUnmounted(() => {
  cleanupGuard?.()
})

const handleSave = saveChanges(async () => {
  // Validate password if provided (only for local auth users)
  if (canChangePassword.value && passwordData.value.new) {
    if (passwordData.value.new !== passwordData.value.confirm) {
      error('Passwords do not match')
      throw new Error('Validation failed')
    }

    if (passwordData.value.new.length < 8) {
      error('Password must be at least 8 characters')
      throw new Error('Validation failed')
    }
  }

  try {
    loading.value = true
    
    // Update profile
    await profileApi.updateProfile(formData.value)
    
    // Change password if provided and allowed
    if (canChangePassword.value && passwordData.value.current && passwordData.value.new) {
      await profileApi.changePassword(passwordData.value.current, passwordData.value.new)
      passwordData.value = { current: '', new: '', confirm: '' }
    }
    
    success('Profile updated successfully')
    originalData.value = { ...formData.value }
  } catch (err: any) {
    error(err.message || 'Failed to update profile')
    throw err
  } finally {
    loading.value = false
  }
})

const handleDiscard = () => {
  discardChanges()
  passwordData.value = { current: '', new: '', confirm: '' }
}
</script>
