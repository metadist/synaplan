<template>
  <nav class="flex flex-col gap-4 px-3 py-2" :aria-label="$t('nav.profile')">
    <div class="flex flex-col gap-0.5">
      <router-link
        v-for="item in preferenceLinks"
        :key="item.slug"
        :to="item.to"
        class="flex items-center min-h-11 px-2 rounded-lg text-[15px] transition-colors"
        :class="linkClass(item.path)"
        :aria-current="isCurrent(item.path) ? 'page' : undefined"
        :data-testid="`link-sidebar-v2-settings-${item.slug}`"
      >
        <span class="truncate">{{ item.label }}</span>
      </router-link>
    </div>

    <div v-if="accountLinks.length > 0" class="flex flex-col gap-0.5">
      <router-link
        v-for="item in accountLinks"
        :key="item.id"
        :to="item.to"
        class="flex items-center gap-2 min-h-11 px-2 rounded-lg text-[15px] transition-colors"
        :class="[linkClass(item.path), item.locked ? 'opacity-60' : '']"
        :aria-current="isCurrent(item.path) ? 'page' : undefined"
        :data-testid="`link-sidebar-v2-${item.id}`"
      >
        <span class="flex-1 truncate">{{ item.label }}</span>
        <Icon
          v-if="item.locked"
          icon="mdi:lock"
          class="w-3.5 h-3.5 flex-shrink-0 text-orange-500 dark:text-orange-400"
        />
      </router-link>
    </div>

    <div v-if="deleteLink" class="flex flex-col gap-0.5">
      <router-link
        :to="deleteLink.to"
        class="flex items-center min-h-11 px-2 rounded-lg text-[15px] transition-colors"
        :class="linkClass(deleteLink.path)"
        :aria-current="isCurrent(deleteLink.path) ? 'page' : undefined"
        data-testid="link-sidebar-v2-settings-delete"
      >
        <span class="truncate">{{ deleteLink.label }}</span>
      </router-link>
    </div>
  </nav>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'
import { isPurchaseAllowed } from '@/services/api/nativeServer'
import { useSettingsSections } from '@/composables/useSettingsSections'

const route = useRoute()
const { t } = useI18n()
const authStore = useAuthStore()
const configStore = useConfigStore()
const { sections } = useSettingsSections()
const purchaseAllowed = isPurchaseAllowed()

function toLink(item: { slug: string; labelKey: string }) {
  return {
    slug: item.slug,
    label: t(item.labelKey),
    path: `/settings/${item.slug}`,
    to: `/settings/${item.slug}`,
  }
}

const preferenceLinks = computed(() =>
  sections.value.filter((item) => item.slug !== 'delete').map(toLink)
)

const deleteLink = computed(() => {
  const item = sections.value.find((entry) => entry.slug === 'delete')
  return item ? toLink(item) : null
})

const memoriesEnabled = computed(() => authStore.user?.memoriesEnabled !== false)
const memoryService = computed(() => configStore.features?.memoryService ?? false)
const showSubscription = computed(
  () => !authStore.isAdmin && configStore.billing.enabled && purchaseAllowed && authStore.isPro
)

const accountLinks = computed(() => {
  const links: Array<{
    id: string
    label: string
    path: string
    to: string
    locked?: boolean
  }> = []
  if (memoryService.value) {
    const locked = !memoriesEnabled.value
    links.push({
      id: 'memories',
      label: t('pageTitles.memories'),
      path: '/memories',
      to: locked ? '/settings/chat?highlight=memories' : '/memories',
      locked,
    })
  }
  links.push({
    id: 'statistics',
    label: t('nav.statistics'),
    path: '/statistics',
    to: '/statistics',
  })
  if (memoryService.value) {
    links.push({
      id: 'feedback',
      label: t('pageTitles.feedback'),
      path: '/feedbacks',
      to: '/feedbacks',
    })
  }
  if (showSubscription.value) {
    links.push({
      id: 'subscription',
      label: t('nav.subscription'),
      path: '/subscription',
      to: '/subscription',
    })
  }
  return links
})

function isCurrent(path: string): boolean {
  return route.path === path || route.path.startsWith(`${path}/`)
}

function linkClass(path: string): string {
  return isCurrent(path)
    ? 'text-[var(--brand)] bg-[var(--brand)]/[0.08] font-medium'
    : 'txt-primary hover:bg-black/[0.04] dark:hover:bg-white/[0.04]'
}
</script>
