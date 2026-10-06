<template>
  <div class="space-y-6">
    <div
      v-if="sectionNeedsProfile && profileLoadFailed"
      class="surface-card rounded-lg p-6"
      role="alert"
      data-testid="state-profile-load-failed"
    >
      <p class="txt-primary text-sm">{{ $t('profile.loadFailed') }}</p>
      <button
        type="button"
        class="btn-primary mt-4 px-4 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
        data-testid="btn-profile-retry"
        :disabled="loading"
        @click="loadProfile"
      >
        {{ $t('common.retry') }}
      </button>
    </div>

    <form
      v-else
      class="@container space-y-6"
      autocomplete="off"
      data-testid="comp-profile-form"
      :aria-busy="loading"
      @submit.prevent="handleSave"
    >
      <div v-if="section === 'profile'" id="profile" class="space-y-6" data-testid="page-profile">
        <p
          v-if="loading && !profileLoaded"
          class="txt-secondary text-sm"
          data-testid="state-profile-loading"
        >
          {{ $t('profile.loading') }}
        </p>

        <fieldset
          v-else
          :disabled="!profileLoaded"
          class="space-y-6 border-0 p-0 m-0 min-w-0 disabled:opacity-60"
        >
          <ProfilePersonalSection />
          <ProfileCompanySection />
        </fieldset>
      </div>

      <SettingsAppearanceSection v-else-if="section === 'appearance'" />
      <SettingsChatSection v-else-if="section === 'chat'" />
      <SettingsDataSection v-else-if="section === 'data'" />
      <ProfileBillingSection v-else-if="section === 'billing'" />
      <fieldset
        v-else-if="section === 'security'"
        :disabled="!profileLoaded"
        class="space-y-6 border-0 p-0 m-0 min-w-0 disabled:opacity-60"
      >
        <ProfileSecuritySection />
      </fieldset>

      <!-- Outside a disabled fieldset. Enter saves the server, not the profile. -->
      <section v-else-if="section === 'app'" id="app-server" @keydown.enter.prevent>
        <NativeServerControl />
      </section>

      <ProfileLegalSection v-else-if="section === 'legal'" />
      <ProfileDeleteSection v-else-if="section === 'delete'" />
    </form>
  </div>

  <UnsavedChangesBar
    :show="profileLoaded && hasUnsavedChanges"
    @save="handleSave"
    @discard="handleDiscard"
  />
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { provideProfileSettings } from '@/composables/useProfileSettings'
import UnsavedChangesBar from '@/components/UnsavedChangesBar.vue'
import NativeServerControl from '@/components/NativeServerControl.vue'
import SettingsAppearanceSection from '@/components/settings/SettingsAppearanceSection.vue'
import SettingsChatSection from '@/components/settings/SettingsChatSection.vue'
import SettingsDataSection from '@/components/settings/SettingsDataSection.vue'
import ProfilePersonalSection from '@/components/settings/ProfilePersonalSection.vue'
import ProfileCompanySection from '@/components/settings/ProfileCompanySection.vue'
import ProfileBillingSection from '@/components/settings/ProfileBillingSection.vue'
import ProfileSecuritySection from '@/components/settings/ProfileSecuritySection.vue'
import ProfileLegalSection from '@/components/settings/ProfileLegalSection.vue'
import ProfileDeleteSection from '@/components/settings/ProfileDeleteSection.vue'

const route = useRoute()

const {
  loading,
  profileLoaded,
  profileLoadFailed,
  loadProfile,
  hasUnsavedChanges,
  handleSave,
  handleDiscard,
} = provideProfileSettings()

const section = computed(() => {
  const param = route.params.section
  return typeof param === 'string' ? param : 'profile'
})

const sectionNeedsProfile = computed(() =>
  ['profile', 'billing', 'security', 'legal', 'delete'].includes(section.value)
)
</script>
