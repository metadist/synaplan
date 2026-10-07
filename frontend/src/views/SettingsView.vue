<template>
  <MainLayout>
    <div class="min-h-full bg-chat px-4 py-6 md:px-8 md:py-8" data-testid="page-settings">
      <div
        class="@container mx-auto w-full max-w-[60rem] space-y-6"
        data-testid="section-settings-column"
      >
        <PageHeader
          :title="pageTitle"
          :subtitle="pageSubtitle"
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
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSettingsSections } from '@/composables/useSettingsSections'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import SettingsAppearanceSection from '@/components/settings/SettingsAppearanceSection.vue'
import SettingsAccountPage from '@/components/settings/SettingsAccountPage.vue'

const { t } = useI18n()
const route = useRoute()
const authStore = useAuthStore()
const { sections } = useSettingsSections()

const pageTitle = computed(() => {
  if (!authStore.isAuthenticated) return t('settings.title')
  const slug = typeof route.params.section === 'string' ? route.params.section : 'profile'
  const match = sections.value.find((item) => item.slug === slug)
  return match ? t(match.labelKey) : t('settings.sections.profile')
})

const pageSubtitle = computed(() =>
  authStore.isAuthenticated ? undefined : t('settings.subtitle')
)
</script>
