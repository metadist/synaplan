<template>
  <MainLayout>
    <div class="min-h-full bg-chat px-4 py-6 md:px-8 md:py-8" data-testid="page-settings">
      <!--
        Centered in the pane beside the desktop sidebar at every width.
        A viewport `md` row starts at the same 768px that reveals the sidebar,
        so the form used to sit in a right-hand column a fixed distance from
        the menu. Container queries below follow this column, not the window.
      -->
      <div
        class="@container mx-auto w-full max-w-3xl space-y-6"
        data-testid="section-settings-column"
      >
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
