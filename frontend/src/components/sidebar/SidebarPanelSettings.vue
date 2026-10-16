<template>
  <nav class="flex flex-col gap-5 px-3 pt-3 pb-3" :aria-label="$t('nav.profile')">
    <section>
      <h3 :class="headingClass">{{ $t('settings.title') }}</h3>
      <div class="flex flex-col gap-0.5">
        <SidebarNavLink
          v-for="item in preferenceLinks"
          :key="item.slug"
          :to="item.to"
          :label="item.label"
          :icon="item.icon"
          :active="isCurrent(item.path)"
          :test-id="`link-sidebar-v2-settings-${item.slug}`"
        />
      </div>
    </section>

    <section v-if="accountLinks.length > 0">
      <h3 :class="headingClass">{{ $t('nav.account') }}</h3>
      <div class="flex flex-col gap-0.5">
        <SidebarNavLink
          v-for="item in accountLinks"
          :key="item.id"
          :to="item.to"
          :label="item.label"
          :icon="item.icon"
          :locked="item.locked"
          :active="isCurrent(item.path)"
          :test-id="`link-sidebar-v2-${item.id}`"
        />
      </div>
    </section>

    <div
      v-if="deleteLink"
      class="flex flex-col gap-0.5 border-t border-black/[0.06] pt-3 dark:border-white/[0.06]"
    >
      <SidebarNavLink
        :to="deleteLink.to"
        :label="deleteLink.label"
        :icon="TrashIcon"
        danger
        :active="isCurrent(deleteLink.path)"
        test-id="link-sidebar-v2-settings-delete"
      />
    </div>
  </nav>
</template>

<script setup lang="ts">
import { computed, type Component } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  ArchiveBoxIcon,
  ChartBarIcon,
  ChatBubbleLeftEllipsisIcon,
  DevicePhoneMobileIcon,
  HandThumbUpIcon,
  CreditCardIcon,
  LightBulbIcon,
  LockClosedIcon,
  ScaleIcon,
  StarIcon,
  SwatchIcon,
  TrashIcon,
  UserCircleIcon,
} from '@heroicons/vue/24/outline'
import SidebarNavLink from './SidebarNavLink.vue'
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

const headingClass =
  'px-2.5 pb-1.5 text-[11px] font-semibold uppercase tracking-wider txt-secondary'

const SECTION_ICONS: Record<string, Component> = {
  profile: UserCircleIcon,
  appearance: SwatchIcon,
  chat: ChatBubbleLeftEllipsisIcon,
  data: ArchiveBoxIcon,
  billing: CreditCardIcon,
  security: LockClosedIcon,
  app: DevicePhoneMobileIcon,
  legal: ScaleIcon,
}

function toLink(item: { slug: string; labelKey: string }) {
  return {
    slug: item.slug,
    label: t(item.labelKey),
    icon: SECTION_ICONS[item.slug],
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
    icon: Component
    path: string
    to: string
    locked?: boolean
  }> = []
  if (memoryService.value) {
    const locked = !memoriesEnabled.value
    links.push({
      id: 'memories',
      label: t('pageTitles.memories'),
      icon: LightBulbIcon,
      path: '/memories',
      to: locked ? '/settings/chat?highlight=memories' : '/memories',
      locked,
    })
  }
  links.push({
    id: 'statistics',
    label: t('nav.statistics'),
    icon: ChartBarIcon,
    path: '/statistics',
    to: '/statistics',
  })
  if (memoryService.value) {
    links.push({
      id: 'feedback',
      label: t('pageTitles.feedback'),
      icon: HandThumbUpIcon,
      path: '/feedbacks',
      to: '/feedbacks',
    })
  }
  if (showSubscription.value) {
    links.push({
      id: 'subscription',
      label: t('nav.subscription'),
      icon: StarIcon,
      path: '/subscription',
      to: '/subscription',
    })
  }
  return links
})

function isCurrent(path: string): boolean {
  return route.path === path || route.path.startsWith(`${path}/`)
}
</script>
