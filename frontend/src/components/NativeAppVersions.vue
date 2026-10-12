<template>
  <div v-if="rows.length > 0" class="surface-card rounded-lg p-6" data-testid="section-app-version">
    <div class="flex items-center gap-3 mb-4">
      <Icon icon="mdi:information-outline" class="w-6 h-6 text-[var(--brand)]" />
      <h3 class="text-lg font-semibold txt-primary">
        {{ $t('nativeServer.appVersion.title') }}
      </h3>
    </div>
    <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
      <template v-for="row in rows" :key="row.key">
        <dt class="txt-secondary">{{ row.label }}</dt>
        <dd class="txt-primary font-medium break-all" :data-testid="`app-version-${row.key}`">
          {{ row.value }}
        </dd>
      </template>
    </dl>
    <p class="mt-4 text-xs txt-secondary">{{ $t('nativeServer.appVersion.hint') }}</p>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useConfigStore } from '@/stores/config'
import { getNativeAppVersions, type NativeAppVersions } from '@/services/api/nativeAppInfo'
import { formatRunningVersion } from '@/utils/formatRunningVersion'

const { t } = useI18n()
const config = useConfigStore()
const versions = ref<NativeAppVersions | null>(null)

const rows = computed(() => {
  if (!versions.value) return []
  const app = formatRunningVersion(versions.value.app)
  const entries = [
    {
      key: 'app',
      label: t('nativeServer.appVersion.app'),
      value:
        app && versions.value.build
          ? t('nativeServer.appVersion.appValue', { version: app, build: versions.value.build })
          : app,
    },
    {
      key: 'web',
      label: t('nativeServer.appVersion.web'),
      value: formatRunningVersion(versions.value.web),
    },
    {
      key: 'server',
      label: t('nativeServer.appVersion.server'),
      value: formatRunningVersion(config.build.version),
    },
  ]
  return entries.filter((entry) => entry.value !== '')
})

onMounted(async () => {
  versions.value = await getNativeAppVersions()
})
</script>
