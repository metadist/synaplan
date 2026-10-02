<template>
  <div class="flex flex-col md:flex-row md:items-start gap-6">
    <SettingsSectionIndex :items="sections" />

    <div class="flex-1 min-w-0 space-y-6">
      <form
        class="space-y-6"
        autocomplete="off"
        data-testid="comp-profile-form"
        :aria-busy="loading"
        @submit.prevent="handleSave"
      >
        <div id="profile" class="scroll-mt-6 space-y-6" data-testid="page-profile">
          <p
            v-if="loading && !profileLoaded"
            class="txt-secondary text-sm"
            data-testid="state-profile-loading"
          >
            {{ $t('profile.loading') }}
          </p>

          <div
            v-else-if="profileLoadFailed"
            class="surface-card rounded-lg p-6"
            role="alert"
            data-testid="state-profile-load-failed"
          >
            <p class="txt-primary text-sm">{{ $t('profile.loadFailed') }}</p>
            <button
              type="button"
              class="btn-primary mt-4 px-4 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
              data-testid="btn-profile-retry"
              :disabled="loading"
              @click="loadProfile"
            >
              {{ $t('common.retry') }}
            </button>
          </div>

          <fieldset
            v-show="!profileLoadFailed"
            :disabled="!profileLoaded"
            class="space-y-6 border-0 p-0 m-0 min-w-0 disabled:opacity-60"
          >
            <ProfilePersonalSection />
            <ProfileCompanySection />
          </fieldset>
        </div>

        <SettingsAppearanceSection />
        <SettingsChatSection />
        <SettingsDataSection />

        <fieldset
          v-show="!profileLoadFailed"
          :disabled="!profileLoaded"
          class="space-y-6 border-0 p-0 m-0 min-w-0 disabled:opacity-60"
        >
          <ProfileBillingSection v-if="showBilling" />
          <ProfileSecuritySection />
        </fieldset>

        <!-- Outside the disabled fieldset. Enter saves the server, not the profile. -->
        <section v-if="showApp" id="app" class="scroll-mt-6" @keydown.enter.prevent>
          <NativeServerControl />
        </section>

        <fieldset
          v-show="!profileLoadFailed"
          :disabled="!profileLoaded"
          class="space-y-6 border-0 p-0 m-0 min-w-0 disabled:opacity-60"
        >
          <ProfileLegalSection />
          <ProfileDeleteSection />
        </fieldset>

        <div class="h-20"></div>
      </form>
    </div>
  </div>

  <UnsavedChangesBar
    :show="profileLoaded && hasUnsavedChanges"
    @save="handleSave"
    @discard="handleDiscard"
  />
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useConfigStore } from '@/stores/config'
import { isNativeServerControlAvailable, isPurchaseAllowed } from '@/services/api/nativeServer'
import { provideProfileSettings } from '@/composables/useProfileSettings'
import UnsavedChangesBar from '@/components/UnsavedChangesBar.vue'
import NativeServerControl from '@/components/NativeServerControl.vue'
import SettingsSectionIndex, {
  type SettingsSectionLink,
} from '@/components/settings/SettingsSectionIndex.vue'
import SettingsAppearanceSection from '@/components/settings/SettingsAppearanceSection.vue'
import SettingsChatSection from '@/components/settings/SettingsChatSection.vue'
import SettingsDataSection from '@/components/settings/SettingsDataSection.vue'
import ProfilePersonalSection from '@/components/settings/ProfilePersonalSection.vue'
import ProfileCompanySection from '@/components/settings/ProfileCompanySection.vue'
import ProfileBillingSection from '@/components/settings/ProfileBillingSection.vue'
import ProfileSecuritySection from '@/components/settings/ProfileSecuritySection.vue'
import ProfileLegalSection from '@/components/settings/ProfileLegalSection.vue'
import ProfileDeleteSection from '@/components/settings/ProfileDeleteSection.vue'

const config = useConfigStore()
const purchaseAllowed = isPurchaseAllowed()
const showApp = isNativeServerControlAvailable()
const showBilling = computed(() => config.billing.enabled && purchaseAllowed)

const {
  loading,
  profileLoaded,
  profileLoadFailed,
  loadProfile,
  hasUnsavedChanges,
  handleSave,
  handleDiscard,
} = provideProfileSettings()

const sections = computed<SettingsSectionLink[]>(() => {
  const failed = profileLoadFailed.value
  const items: SettingsSectionLink[] = [
    { id: 'profile', labelKey: 'settings.sections.profile' },
    { id: 'appearance', labelKey: 'settings.sections.appearance' },
    { id: 'chat', labelKey: 'settings.sections.chat' },
    { id: 'data', labelKey: 'settings.sections.data' },
  ]
  if (!failed && showBilling.value) {
    items.push({ id: 'billing', labelKey: 'settings.sections.billing' })
  }
  if (!failed) {
    items.push({ id: 'security', labelKey: 'settings.sections.security' })
  }
  if (showApp) {
    items.push({ id: 'app', labelKey: 'settings.sections.app' })
  }
  if (!failed) {
    items.push(
      { id: 'legal', labelKey: 'settings.sections.legal' },
      { id: 'delete', labelKey: 'settings.sections.delete' }
    )
  }
  return items
})
</script>
