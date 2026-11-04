<template>
  <MainLayout>
    <div class="min-h-full bg-chat px-3 py-4 sm:p-4 md:p-8" data-testid="page-settings">
      <div
        class="@container mx-auto w-full max-w-[100rem] space-y-6"
        data-testid="section-settings-column"
      >
        <RouterLink
          v-if="showSectionBack"
          to="/settings"
          class="inline-flex min-h-11 items-center gap-1 rounded-xl px-1 text-sm font-medium txt-secondary focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
          data-testid="link-settings-index"
        >
          <ChevronLeftIcon class="h-4 w-4" aria-hidden="true" />
          {{ $t('nav.preferences') }}
        </RouterLink>

        <PageHeader
          :title="pageTitle"
          :subtitle="pageSubtitle"
          icon="heroicons:cog-6-tooth"
          data-testid="section-header"
        />

        <div class="space-y-6" data-testid="section-general-settings">
          <SettingsPhoneIndex v-if="isHub && authStore.isAuthenticated" />
          <SettingsAppearanceSection v-else-if="!authStore.isAuthenticated" />
          <SettingsAccountPage v-else />
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ChevronLeftIcon } from '@heroicons/vue/24/outline'
import { useAuthStore } from '@/stores/auth'
import { usePhoneChrome } from '@/composables/usePhoneChrome'
import { settingsLocationFor, useSettingsSections } from '@/composables/useSettingsSections'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import SettingsAppearanceSection from '@/components/settings/SettingsAppearanceSection.vue'
import SettingsAccountPage from '@/components/settings/SettingsAccountPage.vue'
import SettingsPhoneIndex from '@/components/settings/SettingsPhoneIndex.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const { sections } = useSettingsSections()
const { isPhoneChrome } = usePhoneChrome()

const isHub = computed(() => route.name === 'settings')

function leaveHubOnDesktop() {
  if (isPhoneChrome.value || route.name !== 'settings') return
  void router.replace(settingsLocationFor(route))
}

onMounted(leaveHubOnDesktop)
watch(isPhoneChrome, leaveHubOnDesktop)

const showSectionBack = computed(
  () => isPhoneChrome.value && authStore.isAuthenticated && !isHub.value
)

const pageTitle = computed(() => {
  if (!authStore.isAuthenticated || isHub.value) return t('settings.title')
  const slug = typeof route.params.section === 'string' ? route.params.section : 'profile'
  const match = sections.value.find((item) => item.slug === slug)
  return match ? t(match.labelKey) : t('settings.sections.profile')
})

const pageSubtitle = computed(() =>
  !authStore.isAuthenticated || isHub.value ? t('settings.subtitle') : undefined
)
</script>
