import { getErrorMessage } from '@/utils/errorMessage'
import {
  ref,
  computed,
  watch,
  onMounted,
  onUnmounted,
  nextTick,
  provide,
  inject,
  type InjectionKey,
} from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { type UserProfile } from '@/mocks/profile'
import { listTimezones, timezoneGroupsForSelect } from '@/utils/timezones'
import { useNotification } from '@/composables/useNotification'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { profileApi } from '@/services/api'
import { ApiError } from '@/services/api/httpClient'
import { useAuthStore } from '@/stores/auth'
import { isNativeApp } from '@/services/api/nativeRuntime'
import {
  isBiometricAvailable,
  isBiometricLockEnabled,
  setBiometricLockEnabled,
  verifyBiometric,
} from '@/services/biometricLock'

const EMAIL_INPUT_CLASS =
  'w-full px-4 py-2.5 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed'

/**
 * Profile form state for the signed-in settings page.
 * Call once via provideProfileSettings() — sections must inject, not call this again.
 */
export function useProfileSettings() {
  const router = useRouter()
  const route = useRoute()
  const authStore = useAuthStore()
  const { error } = useNotification()
  const { t } = useI18n()

  const shouldHighlight = ref(false)
  const biometricAvailable = ref(false)
  const biometricLockOn = ref(isBiometricLockEnabled())
  const showBiometricSection = computed(() => isNativeApp() && biometricAvailable.value)

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
  const profileLoaded = ref(false)
  const profileLoadFailed = ref(false)
  const authProvider = ref<string>('Email/Password')
  const isExternalAuth = ref(false)
  const emailPassword = ref('')
  const timezoneQuery = ref('')
  const saveSuccessMessage = ref('')
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

  // Browser password autofill can fill fields without a real user edit.
  // Only treat password fields as dirty after an explicit input event.
  const passwordTouchedByUser = ref(false)

  const hasPasswordChanges = computed(
    () =>
      passwordTouchedByUser.value &&
      !!(passwordData.value.current || passwordData.value.new || passwordData.value.confirm)
  )

  const canChangeEmail = computed(
    () => profileLoaded.value && canChangePassword.value && !isExternalAuth.value
  )

  function normalizeEmail(value: string): string {
    return value.trim().toLowerCase()
  }

  const emailChanged = computed(
    () => normalizeEmail(formData.value.email) !== normalizeEmail(originalData.value.email)
  )

  const emailFieldHint = computed(() => {
    if (!profileLoaded.value || canChangeEmail.value) return t('profile.personalInfo.emailHint')
    if (isExternalAuth.value) {
      return t('profile.personalInfo.managedBy', { provider: authProvider.value })
    }
    return t('profile.personalInfo.emailLockedHint')
  })

  const timezoneOptions = computed(() => listTimezones(new Date(), formData.value.timezone))
  const timezoneSelect = computed(() =>
    timezoneGroupsForSelect(timezoneOptions.value, timezoneQuery.value, formData.value.timezone)
  )
  const timezoneGroups = computed(() => timezoneSelect.value.groups)
  const timezoneSearchMiss = computed(
    () => timezoneQuery.value.trim().length > 0 && timezoneSelect.value.matchedCount === 0
  )

  const { hasUnsavedChanges, saveChanges, discardChanges, setupNavigationGuard } =
    useUnsavedChanges(formData, originalData, {
      extraDirtyCheck: hasPasswordChanges,
      successMessage: () => saveSuccessMessage.value || t('unsavedChanges.saved'),
    })

  function markPasswordTouched() {
    passwordTouchedByUser.value = true
  }

  let cleanupGuard: (() => void) | undefined
  let highlightTimer: ReturnType<typeof setTimeout> | undefined

  async function loadProfile() {
    loading.value = true
    try {
      const response = await profileApi.getProfile()
      if (!response.success || !response.profile) {
        profileLoadFailed.value = true
        return
      }

      Object.assign(formData.value, response.profile)
      originalData.value = { ...formData.value }

      canChangePassword.value = response.profile.canChangePassword ?? true
      authProvider.value = response.profile.authProvider ?? 'Email/Password'
      isExternalAuth.value = response.profile.isExternalAuth ?? false
      externalAuthLastLogin.value = response.profile.externalAuthInfo?.lastLogin ?? null

      if (response.profile.isAdmin !== undefined && authStore.user) {
        authStore.user.isAdmin = response.profile.isAdmin
      }

      if (authStore.user) {
        authStore.user.memoriesEnabled = response.profile.memoriesEnabled
      }

      profileLoaded.value = true
      profileLoadFailed.value = false
    } catch {
      profileLoadFailed.value = true
    } finally {
      loading.value = false
    }
  }

  async function focusRequestedSection(): Promise<void> {
    const wantsMemories = route.query.highlight === 'memories' || route.hash === '#memories'
    await nextTick()
    if (wantsMemories) {
      document.getElementById('memories')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
      shouldHighlight.value = true
      if (highlightTimer !== undefined) {
        clearTimeout(highlightTimer)
      }
      highlightTimer = setTimeout(() => {
        shouldHighlight.value = false
      }, 3000)
      if (route.query.highlight) {
        await router.replace({ path: route.path, hash: '#memories', query: {} })
      }
      return
    }
    const raw = route.hash.replace(/^#/, '')
    // `#app` is the Vue mount node. The native-server section uses `#app-server`.
    const id = raw === 'app' ? 'app-server' : raw
    if (!id) return
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  onMounted(async () => {
    biometricAvailable.value = await isBiometricAvailable()
    cleanupGuard = setupNavigationGuard()
    await loadProfile()
    await focusRequestedSection()
  })

  // The account page stays mounted while the hash changes (sidebar memories
  // link, section index, browser back). Mount-only focusing would miss those.
  watch(
    () => `${route.hash}|${String(route.query.highlight ?? '')}`,
    () => {
      void focusRequestedSection()
    }
  )

  onUnmounted(() => {
    cleanupGuard?.()
    if (highlightTimer !== undefined) {
      clearTimeout(highlightTimer)
    }
  })

  async function toggleBiometricLock(): Promise<void> {
    if (biometricLockOn.value) {
      setBiometricLockEnabled(false)
      biometricLockOn.value = false
      return
    }

    // Require a successful check before enabling so the user can't lock themselves
    // out with an unusable or unenrolled sensor.
    const ok = await verifyBiometric(t('profile.appSecurity.verifyReason'))
    if (!ok) {
      error(t('profile.appSecurity.enableFailed'))
      return
    }
    setBiometricLockEnabled(true)
    biometricLockOn.value = true
  }

  function isWrongCurrentPassword(err: unknown): boolean {
    return (
      err instanceof ApiError &&
      err.status === 403 &&
      err.message === 'Current password is incorrect'
    )
  }

  function profileSaveError(err: unknown): string {
    if (err instanceof ApiError) {
      switch (err.code) {
        case 'email_password_incorrect':
          return t('profile.personalInfo.emailPasswordRejected')
        case 'email_password_required':
          return t('profile.personalInfo.emailPasswordRequired')
        case 'email_invalid':
          return t('profile.personalInfo.emailInvalid')
        case 'email_taken':
          return t('profile.personalInfo.emailTaken')
        case 'email_reserved':
          return t('profile.personalInfo.emailReserved')
        case 'email_managed':
          return t('profile.personalInfo.emailManaged')
        case 'timezone_invalid':
          return t('profile.accountSettings.timezoneInvalid')
      }
    }
    return getErrorMessage(err) || t('profile.saveFailed')
  }

  const handleSave = saveChanges(async () => {
    if (!profileLoaded.value) {
      throw new Error('Profile has not loaded')
    }

    const changingEmail = emailChanged.value
    saveSuccessMessage.value = t('unsavedChanges.saved')

    if (changingEmail) {
      formData.value.email = normalizeEmail(formData.value.email)
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.value.email)) {
        error(t('profile.personalInfo.emailInvalid'))
        throw new Error('Validation failed')
      }
      if (!emailPassword.value) {
        error(t('profile.personalInfo.emailPasswordRequired'))
        throw new Error('Validation failed')
      }
    }

    if (canChangePassword.value && passwordData.value.new) {
      if (passwordData.value.new !== passwordData.value.confirm) {
        error(t('profile.changePassword.mismatch'))
        throw new Error('Validation failed')
      }

      if (passwordData.value.new.length < 8) {
        error(t('profile.changePassword.tooShort'))
        throw new Error('Validation failed')
      }
    }

    let profileSaved = false
    let passwordFailureShown = false
    try {
      loading.value = true

      // The sign-in email is included. The password is sent only when that
      // address actually changed. Language is device-and-account via the
      // language buttons, not this payload.
      const profilePayload: Omit<UserProfile, 'language'> & { language?: string } = {
        ...formData.value,
      }
      delete profilePayload.language
      const updated = await profileApi.updateProfile(
        profilePayload,
        changingEmail ? emailPassword.value : undefined
      )
      if (typeof updated?.email === 'string' && updated.email !== '') {
        formData.value.email = updated.email
      }
      if (changingEmail) {
        emailPassword.value = ''
        saveSuccessMessage.value = t('profile.personalInfo.emailSaved', {
          email: formData.value.email,
        })
      }
      profileSaved = true

      await authStore.refreshUser()

      if (authStore.user) {
        authStore.user.memoriesEnabled = formData.value.memoriesEnabled
      }

      if (canChangePassword.value && passwordData.value.current && passwordData.value.new) {
        try {
          await profileApi.changePassword(passwordData.value.current, passwordData.value.new)
          passwordData.value = { current: '', new: '', confirm: '' }
          passwordTouchedByUser.value = false
        } catch (passwordErr: unknown) {
          // The profile write already committed. Keep those fields clean so
          // Discard cannot revert them, and leave the password fields dirty.
          originalData.value = { ...formData.value }
          passwordFailureShown = true
          error(
            isWrongCurrentPassword(passwordErr)
              ? t('profile.changePassword.profileSavedPasswordRejected')
              : t('profile.changePassword.profileSavedPasswordFailed')
          )
          throw passwordErr
        }
      }

      originalData.value = { ...formData.value }
    } catch (err: unknown) {
      if (!passwordFailureShown) {
        if (profileSaved) {
          originalData.value = { ...formData.value }
        }
        error(profileSaveError(err))
      }
      throw err
    } finally {
      loading.value = false
    }
  })

  const handleDiscard = () => {
    discardChanges()
    passwordData.value = { current: '', new: '', confirm: '' }
    passwordTouchedByUser.value = false
    emailPassword.value = ''
  }

  const handleDeleteAccount = async () => {
    if (!canConfirmDelete.value) return

    try {
      deletingAccount.value = true

      const payload = isExternalAuth.value
        ? { password: 'EXTERNAL_AUTH_DELETE' }
        : { password: deleteConfirmPassword.value }

      await profileApi.deleteAccount(payload.password)

      await authStore.logout()
      showDeleteModal.value = false
      router.push('/login')
    } catch (err: unknown) {
      error(getErrorMessage(err) || 'Failed to delete account')
    } finally {
      deletingAccount.value = false
      deleteConfirmPassword.value = ''
      deleteConfirmText.value = ''
    }
  }

  return {
    formData,
    passwordData,
    loading,
    canChangePassword,
    profileLoaded,
    profileLoadFailed,
    authProvider,
    isExternalAuth,
    emailPassword,
    timezoneQuery,
    externalAuthLastLogin,
    showDeleteModal,
    deleteConfirmPassword,
    deleteConfirmText,
    deletingAccount,
    canConfirmDelete,
    canChangeEmail,
    emailChanged,
    emailFieldHint,
    emailInputClass: EMAIL_INPUT_CLASS,
    timezoneGroups,
    timezoneSearchMiss,
    hasUnsavedChanges,
    shouldHighlight,
    showBiometricSection,
    biometricLockOn,
    markPasswordTouched,
    loadProfile,
    toggleBiometricLock,
    handleSave,
    handleDiscard,
    handleDeleteAccount,
  }
}

export type ProfileSettings = ReturnType<typeof useProfileSettings>

export const profileSettingsKey: InjectionKey<ProfileSettings> = Symbol('profileSettings')

export function provideProfileSettings(): ProfileSettings {
  const settings = useProfileSettings()
  provide(profileSettingsKey, settings)
  return settings
}

export function injectProfileSettings(): ProfileSettings {
  const settings = inject(profileSettingsKey)
  if (!settings) {
    throw new Error('Profile settings are only available on the signed-in settings page')
  }
  return settings
}

export function injectProfileSettingsOptional(): ProfileSettings | null {
  return inject(profileSettingsKey, null)
}
