<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-settings"
    >
      <div class="max-w-6xl mx-auto space-y-6">
        <PageHeader
          :title="$t('settings.title')"
          :subtitle="$t('settings.subtitle')"
          icon="heroicons:cog-6-tooth"
          data-testid="section-header"
        />

        <div class="space-y-6" data-testid="section-general-settings">
          <SettingsAppearanceSection v-if="!authStore.isAuthenticated" />
          <SettingsAccountPage v-else />
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import SettingsAppearanceSection from '@/components/settings/SettingsAppearanceSection.vue'
import SettingsAccountPage from '@/components/settings/SettingsAccountPage.vue'

const authStore = useAuthStore()
</script>
