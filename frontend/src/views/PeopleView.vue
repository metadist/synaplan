<template>
  <MainLayout data-testid="view-people">
    <div class="container mx-auto px-6 py-8 max-w-[100rem] overflow-x-hidden">
      <button
        type="button"
        class="text-xs txt-secondary hover:txt-primary transition-colors mb-3 inline-flex items-center gap-1.5"
        data-testid="link-people-back-operate"
        @click="router.push({ name: 'admin' })"
      >
        <Icon icon="heroicons:arrow-left" class="w-3.5 h-3.5" />
        {{ $t('people.backToOperate') }}
      </button>
      <PageHeader
        :title="$t('people.title')"
        :subtitle="$t('people.subtitle')"
        icon="mdi:account-group"
      >
        <TabNav
          v-if="tabNavItems.length > 1"
          :model-value="activeTab"
          :tabs="tabNavItems"
          :aria-label="$t('people.title')"
          mobile-trigger-testid="tab-people-mobile-trigger"
          mobile-menu-testid="tab-people-mobile-menu"
          @update:model-value="onTabNavChange"
        />
      </PageHeader>

      <p v-if="peopleIntro" class="text-sm txt-secondary mb-4" data-testid="text-people-intro">
        {{ peopleIntro }}
      </p>

      <UsersTab v-if="activeTab === 'users'" :show-iam-columns="isIamGroupsEnabled()" />
      <GroupsTab v-else-if="activeTab === 'groups'" />
      <PoliciesTab v-else-if="activeTab === 'policies'" />
      <PlatformInstancesTab v-else-if="activeTab === 'linked-platforms'" />
      <AdminModerationPanel v-else-if="activeTab === 'moderation'" />
      <AuditTab v-else-if="activeTab === 'audit'" />
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, defineAsyncComponent, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import TabNav, { type TabNavItem } from '@/components/TabNav.vue'
import UsersTab from '@/components/people/UsersTab.vue'
import GroupsTab from '@/components/people/GroupsTab.vue'
import PoliciesTab from '@/components/people/PoliciesTab.vue'
import AuditTab from '@/components/people/AuditTab.vue'
import PlatformInstancesTab from '@/components/people/PlatformInstancesTab.vue'
import { isIamGroupsEnabled, isIamPoliciesEnabled } from '@/composables/useIamFeature'
import { isPlatformLinksEnabled } from '@/composables/usePlatformLinksFeature'
import { useI18n } from 'vue-i18n'

const AdminModerationPanel = defineAsyncComponent(
  () => import('@/components/admin/AdminModerationPanel.vue')
)

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

type PeopleTab = 'users' | 'groups' | 'policies' | 'linked-platforms' | 'moderation' | 'audit'

function isPeopleTab(id: string): id is PeopleTab {
  return (
    id === 'users' ||
    id === 'groups' ||
    id === 'policies' ||
    id === 'linked-platforms' ||
    id === 'moderation' ||
    id === 'audit'
  )
}

/** One sentence per tab; Platform instances carries its own intro. */
const PEOPLE_INTRO_TABS = new Set(['users', 'groups', 'policies', 'moderation', 'audit'])
const peopleIntro = computed(() =>
  PEOPLE_INTRO_TABS.has(activeTab.value) ? t(`people.intro.${activeTab.value}`) : ''
)

const tabNavItems = computed<TabNavItem[]>(() => {
  const tabs: TabNavItem[] = [
    {
      id: 'users',
      label: t('people.tabs.users'),
      icon: 'mdi:account-multiple',
      testid: 'tab-users',
    },
  ]
  if (isIamGroupsEnabled()) {
    tabs.push({
      id: 'groups',
      label: t('people.tabs.groups'),
      icon: 'mdi:account-group',
      testid: 'tab-groups',
    })
  }
  if (isIamPoliciesEnabled()) {
    tabs.push({
      id: 'policies',
      label: t('people.tabs.policies'),
      icon: 'mdi:shield-account',
      testid: 'tab-policies',
    })
  }
  if (isPlatformLinksEnabled()) {
    tabs.push({
      id: 'linked-platforms',
      label: t('people.tabs.linkedPlatforms'),
      icon: 'mdi:link-variant',
      testid: 'tab-linked-platforms',
    })
  }
  tabs.push({
    id: 'moderation',
    label: t('people.tabs.moderation'),
    icon: 'mdi:flag-outline',
    testid: 'tab-moderation',
  })
  if (isIamGroupsEnabled()) {
    tabs.push({
      id: 'audit',
      label: t('people.tabs.audit'),
      icon: 'mdi:clipboard-text-clock',
      testid: 'tab-audit',
    })
  }
  return tabs
})

function tabFromQuery(): PeopleTab {
  const tab = route.query.tab
  if (
    typeof tab === 'string' &&
    isPeopleTab(tab) &&
    tabNavItems.value.some((item) => item.id === tab)
  ) {
    return tab
  }
  return 'users'
}

const activeTab = ref<PeopleTab>(tabFromQuery())

function onTabNavChange(id: string) {
  if (!isPeopleTab(id)) return
  activeTab.value = id
  const query = { ...route.query }
  if (id === 'users') {
    delete query.tab
  } else {
    query.tab = id
  }
  void router.replace({ query })
}

watch(
  () => route.query.tab,
  () => {
    activeTab.value = tabFromQuery()
  }
)
</script>
