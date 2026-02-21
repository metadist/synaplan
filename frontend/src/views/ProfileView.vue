<template>
  <MainLayout data-testid="page-profile">
    <div class="h-full overflow-y-auto scroll-thin">
      <div class="max-w-4xl mx-auto p-4 md:p-8">
        <div class="mb-8" data-testid="section-header">
          <h1 class="text-3xl font-bold txt-primary mb-2">{{ $t('profile.title') }}</h1>
          <p class="txt-secondary">{{ $t('profile.subtitle') }}</p>
        </div>

        <form class="space-y-6" data-testid="comp-profile-form" @submit.prevent="handleSave">
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

          <section
            v-if="config.billing.enabled"
            class="surface-card rounded-lg p-6"
            data-testid="section-billing"
          >
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

          <section
            ref="memoriesSection"
            class="surface-card rounded-lg p-6 transition-all duration-500"
            :class="{ 'ring-4 ring-brand-500/50 shadow-2xl': shouldHighlight }"
            data-testid="section-memories-settings"
          >
            <h2 class="text-xl font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="mdi:brain" class="w-5 h-5" />
              {{ $t('profile.memories.title') }}
            </h2>
            <p class="txt-secondary text-sm mb-6">
              {{ $t('profile.memories.subtitle') }}
            </p>

            <div
              class="flex items-start justify-between gap-4 p-4 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20"
            >
              <div class="min-w-0">
                <p class="txt-primary font-medium">
                  {{ $t('profile.memories.toggleLabel') }}
                </p>
                <p class="txt-secondary text-sm mt-1">
                  {{
                    formData.memoriesEnabled
                      ? $t('profile.memories.enabledHint')
                      : $t('profile.memories.disabledHint')
                  }}
                </p>
              </div>

              <label class="relative inline-flex items-center cursor-pointer select-none">
                <input v-model="formData.memoriesEnabled" type="checkbox" class="sr-only" />
                <div
                  class="w-11 h-6 rounded-full transition-colors"
                  :class="
                    formData.memoriesEnabled ? 'bg-[var(--brand)]' : 'bg-gray-300 dark:bg-gray-700'
                  "
                />
                <div
                  class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white transition-transform shadow"
                  :class="formData.memoriesEnabled ? 'translate-x-5' : 'translate-x-0'"
                />
              </label>
            </div>
          </section>

          <section class="surface-card rounded-lg p-6" data-testid="section-change-password">
            <h2 class="text-xl font-semibold txt-primary mb-2 flex items-center gap-2">
              <Icon icon="mdi:lock" class="w-5 h-5" />
              {{ $t('profile.changePassword.title') }}
            </h2>

            <!-- External Auth Warning -->
            <div v-if="isExternalAuth" class="mb-6 info-box-blue">
              <div class="flex items-start gap-3">
                <Icon icon="mdi:shield-check" class="w-6 h-6 info-box-blue-icon flex-shrink-0" />
                <div class="flex-1">
                  <p class="text-sm info-box-blue-title mb-1">🔒 Managed by {{ authProvider }}</p>
                  <p class="text-sm info-box-blue-text mb-2">
                    You're using {{ authProvider }} to sign in. Password management is handled
                    through {{ authProvider }}.
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
                  class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  :placeholder="$t('profile.changePassword.confirmPasswordPlaceholder')"
                  data-testid="input-confirm-password"
                />
                <p class="txt-secondary text-sm mt-1">
                  {{ $t('profile.changePassword.confirmPasswordHint') }}
                </p>
              </div>
            </div>
          </section>

          <div class="info-box-blue" data-testid="section-privacy-notice">
            <p class="text-sm info-box-blue-text flex items-start gap-2">
              <Icon
                icon="mdi:information"
                class="w-5 h-5 info-box-blue-icon flex-shrink-0 mt-0.5"
              />
              <span>{{ $t('profile.privacyNotice') }}</span>
            </p>
          </div>

          <!-- Danger Zone -->
          <section
            class="surface-card rounded-lg p-6 border-2 border-red-200 dark:border-red-800/50"
            data-testid="section-danger-zone"
          >
            <h2
              class="text-xl font-semibold text-red-600 dark:text-red-400 mb-2 flex items-center gap-2"
            >
              <Icon icon="mdi:alert" class="w-5 h-5" />
              {{ $t('profile.dangerZone.title') }}
            </h2>
            <p class="txt-secondary text-sm mb-6">{{ $t('profile.dangerZone.subtitle') }}</p>

            <div class="flex items-start gap-4 info-box-red">
              <Icon
                icon="mdi:account-remove"
                class="w-6 h-6 info-box-red-icon flex-shrink-0 mt-0.5"
              />
              <div class="flex-1">
                <h3 class="info-box-red-title mb-1">
                  {{ $t('profile.dangerZone.deleteAccount') }}
                </h3>
                <p class="text-sm info-box-red-text mb-4">
                  {{ $t('profile.dangerZone.deleteAccountDesc') }}
                </p>
                <button
                  type="button"
                  class="btn-danger px-4 py-2 rounded-lg text-sm font-medium"
                  data-testid="btn-delete-account"
                  @click="showDeleteModal = true"
                >
                  {{ $t('profile.dangerZone.deleteButton') }}
                </button>
              </div>
            </div>
          </section>

          <div class="h-20"></div>
        </form>
      </div>
    </div>

    <UnsavedChangesBar :show="hasUnsavedChanges" @save="handleSave" @discard="handleDiscard" />

    <!-- Delete Account Modal -->
    <Teleport to="#app">
      <div
        v-if="showDeleteModal"
        class="fixed inset-0 bg-black/50 dark:bg-black/70 flex items-center justify-center z-50 px-4"
        data-testid="modal-delete-account"
        @click.self="showDeleteModal = false"
      >
        <div class="surface-card max-w-lg w-full p-6 space-y-6 animate-scale-in">
          <!-- Header -->
          <div class="text-center">
            <div
              class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center"
            >
              <Icon icon="mdi:alert-circle" class="w-10 h-10 text-red-600 dark:text-red-400" />
            </div>
            <h2 class="text-2xl font-bold txt-primary mb-2">
              {{ $t('profile.deleteAccountModal.title') }}
            </h2>
            <p class="text-sm text-red-600 dark:text-red-400 font-medium">
              {{ $t('profile.deleteAccountModal.warning') }}
            </p>
          </div>

          <!-- Consequences List -->
          <div class="info-box-red">
            <p class="text-sm info-box-red-title mb-3">
              {{ $t('profile.deleteAccountModal.consequences') }}
            </p>
            <ul class="space-y-2 text-sm info-box-red-text">
              <li class="flex items-start gap-2">
                <Icon icon="mdi:close-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
                <span>{{ $t('profile.deleteAccountModal.consequence1') }}</span>
              </li>
              <li class="flex items-start gap-2">
                <Icon icon="mdi:close-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
                <span>{{ $t('profile.deleteAccountModal.consequence2') }}</span>
              </li>
              <li class="flex items-start gap-2">
                <Icon icon="mdi:close-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
                <span>{{ $t('profile.deleteAccountModal.consequence3') }}</span>
              </li>
              <li class="flex items-start gap-2">
                <Icon icon="mdi:close-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
                <span>{{ $t('profile.deleteAccountModal.consequence4') }}</span>
              </li>
            </ul>
          </div>

          <!-- Password Confirmation -->
          <div v-if="!isExternalAuth">
            <label class="block txt-primary font-medium mb-2">
              {{ $t('profile.deleteAccountModal.confirmPassword') }}
            </label>
            <input
              v-model="deleteConfirmPassword"
              type="password"
              class="w-full px-4 py-2.5 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-red-500 transition-colors border-0"
              :placeholder="$t('profile.deleteAccountModal.confirmPasswordPlaceholder')"
              data-testid="input-delete-password"
              @keyup.enter="handleDeleteAccount"
            />
          </div>

          <!-- External Auth Confirmation (type DELETE) -->
          <div v-else>
            <label class="block txt-primary font-medium mb-2">
              {{ $t('profile.deleteAccountModal.externalAuthConfirm') }}
            </label>
            <input
              v-model="deleteConfirmText"
              type="text"
              class="w-full px-4 py-2.5 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-red-500 transition-colors border-0"
              :placeholder="$t('profile.deleteAccountModal.externalAuthPlaceholder')"
              data-testid="input-delete-confirm"
              @keyup.enter="handleDeleteAccount"
            />
          </div>

          <!-- Actions -->
          <div class="flex gap-3 pt-2">
            <button
              type="button"
              class="flex-1 btn-secondary py-2.5 rounded-lg font-medium"
              :disabled="deletingAccount"
              data-testid="btn-cancel-delete"
              @click="showDeleteModal = false"
            >
              {{ $t('profile.deleteAccountModal.cancelButton') }}
            </button>
            <button
              type="button"
              class="flex-1 btn-danger py-2.5 rounded-lg font-medium"
              :disabled="deletingAccount || !canConfirmDelete"
              data-testid="btn-confirm-delete"
              @click="handleDeleteAccount"
            >
              <span v-if="deletingAccount">{{ $t('profile.deleteAccountModal.deleting') }}</span>
              <span v-else>{{ $t('profile.deleteAccountModal.deleteButton') }}</span>
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import UnsavedChangesBar from '@/components/UnsavedChangesBar.vue'
import { countries, languages, timezones, type UserProfile } from '@/mocks/profile'
import { useNotification } from '@/composables/useNotification'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { profileApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const { error } = useNotification()

const memoriesSection = ref<HTMLElement | null>(null)
const shouldHighlight = ref(false)

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
  invoiceEmail: '',
  memoriesEnabled: true,
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
const showDeleteModal = ref(false)
const deleteConfirmPassword = ref('')
const deleteConfirmText = ref('')
const deletingAccount = ref(false)

const canConfirmDelete = computed(() => {
  if (isExternalAuth.value) {
    return deleteConfirmText.value === 'DELETE'
  }
  return deleteConfirmPassword.value.length > 0
})

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

      // Sync isAdmin to auth store if needed
      if (response.profile.isAdmin !== undefined && authStore.user) {
        authStore.user.isAdmin = response.profile.isAdmin
      }

      // Sync per-user memories toggle to auth store (used across UI)
      if (authStore.user) {
        authStore.user.memoriesEnabled = response.profile.memoriesEnabled
      }
    }
  } catch (err: any) {
    error(err.message || 'Failed to load profile')
  } finally {
    loading.value = false
  }

  // Check if we should scroll to and highlight memories section
  if (route.query.highlight === 'memories') {
    await nextTick()
    if (memoriesSection.value) {
      memoriesSection.value.scrollIntoView({ behavior: 'smooth', block: 'center' })
      shouldHighlight.value = true

      // Remove highlight after 3 seconds
      setTimeout(() => {
        shouldHighlight.value = false
      }, 3000)

      // Clear query param
      router.replace({ query: {} })
    }
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

    // Refresh /auth/me to propagate updated flags (e.g. memoriesEnabled)
    await authStore.refreshUser()

    // Keep local authStore flag in sync (refreshUser should do this, but be explicit)
    if (authStore.user) {
      authStore.user.memoriesEnabled = formData.value.memoriesEnabled
    }

    // Change password if provided and allowed
    if (canChangePassword.value && passwordData.value.current && passwordData.value.new) {
      await profileApi.changePassword(passwordData.value.current, passwordData.value.new)
      passwordData.value = { current: '', new: '', confirm: '' }
    }

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

const handleDeleteAccount = async () => {
  if (!canConfirmDelete.value) return

  try {
    deletingAccount.value = true

    const payload = isExternalAuth.value
      ? { password: 'EXTERNAL_AUTH_DELETE' } // Special marker for external auth
      : { password: deleteConfirmPassword.value }

    await profileApi.deleteAccount(payload.password)

    // Clear auth and redirect to login
    await authStore.logout()
    showDeleteModal.value = false
    router.push('/login')
  } catch (err: any) {
    error(err.message || 'Failed to delete account')
  } finally {
    deletingAccount.value = false
    deleteConfirmPassword.value = ''
    deleteConfirmText.value = ''
  }
}
</script>

<style scoped>
@keyframes scaleIn {
  from {
    opacity: 0;
    transform: scale(0.95);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}

.animate-scale-in {
  animation: scaleIn 0.2s ease-out;
}
</style>
