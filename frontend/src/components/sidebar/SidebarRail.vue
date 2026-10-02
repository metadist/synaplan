<template>
  <aside class="v2-sidebar-rail flex flex-col items-center" data-testid="section-sidebar-rail">
    <div class="flex items-center justify-center flex-shrink-0 h-14 w-full">
      <img
        :src="iconSrc"
        :alt="configStore.branding.name"
        class="h-6 w-auto"
        data-testid="img-sidebar-brand"
      />
    </div>

    <nav
      class="flex-1 flex flex-col items-center gap-1 py-1 w-full overflow-y-auto sidebar-scroll"
      :aria-label="$t('nav.menu')"
    >
      <button
        v-for="section in sections"
        :key="section.key"
        type="button"
        class="v2-rail-icon w-11 h-11 inline-flex items-center justify-center rounded-xl"
        :class="activeKey === section.key && 'v2-rail-icon--active'"
        :title="section.label"
        :aria-label="section.label"
        :aria-current="activeKey === section.key ? 'page' : undefined"
        :data-testid="section.testId"
        @click="selectSection(section.key)"
      >
        <component :is="section.icon" class="w-6 h-6" aria-hidden="true" />
      </button>
    </nav>

    <div
      v-if="versionLabel"
      class="mb-3 flex h-11 w-full flex-shrink-0 items-center justify-center px-1"
      data-testid="section-sidebar-v2-version"
    >
      <a
        v-if="showUpdateLink"
        :href="updatesStore.guideUrl ?? undefined"
        target="_blank"
        rel="noopener noreferrer"
        class="max-w-full text-center text-[10px] font-semibold leading-tight break-all"
        :class="
          isSecurityUpdate
            ? 'text-[var(--status-error-text)]'
            : 'text-[var(--status-warning-text)]'
        "
        :title="updateHint"
        :aria-label="updateHint"
        data-testid="link-sidebar-v2-update"
      >
        {{ versionLabel }}
      </a>
      <span
        v-else
        class="max-w-full text-center text-[10px] leading-tight txt-secondary break-all"
        :title="$t('updates.runningVersion', { version: versionLabel })"
        data-testid="text-sidebar-v2-version"
      >
        {{ versionLabel }}
      </span>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { onMounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfigStore } from '@/stores/config'
import { useUpdatesStore } from '@/stores/updates'
import { useTheme } from '@/composables/useTheme'
import { useBrandLogo } from '@/composables/useBrandLogo'
import { useNavSections } from '@/composables/useNavSections'
import { formatRunningVersion } from '@/utils/formatRunningVersion'

const configStore = useConfigStore()
const updatesStore = useUpdatesStore()
const { t } = useI18n()
const { isDark } = useTheme()
const { iconSrc } = useBrandLogo(isDark)
const { sections, activeKey, selectSection, loadFeatureStatus } = useNavSections()

const versionLabel = computed(() => formatRunningVersion(configStore.build.version))
const showUpdateLink = computed(() => updatesStore.showBadge && !!updatesStore.guideUrl)
const isSecurityUpdate = computed(() => updatesStore.severity === 'security')
const updateHint = computed(() =>
  t(isSecurityUpdate.value ? 'updates.badge.securityHint' : 'updates.badge.availableHint', {
    version: updatesStore.latestVersion ?? '',
  })
)

watch(
  () => updatesStore.canRead,
  (canRead) => {
    if (canRead) updatesStore.ensureLoaded()
  },
  { immediate: true }
)

onMounted(() => {
  loadFeatureStatus()
})
</script>
