<template>
  <MainLayout data-testid="view-people">
    <div class="container mx-auto px-6 py-8 max-w-7xl overflow-x-hidden">
      <PageHeader
        :title="$t('people.title')"
        :subtitle="$t('people.subtitle')"
        icon="mdi:account-group"
      >
        <TabNav
          :model-value="activeTab"
          :tabs="tabNavItems"
          :aria-label="$t('people.title')"
          mobile-trigger-testid="tab-people-mobile-trigger"
          mobile-menu-testid="tab-people-mobile-menu"
          @update:model-value="onTabNavChange"
        />
      </PageHeader>

      <UsersTab v-if="activeTab === 'users'" show-iam-columns />
      <GroupsTab v-else-if="activeTab === 'groups'" />
      <PoliciesTab v-else-if="activeTab === 'policies'" />
      <PlatformInstancesTab v-else-if="activeTab === 'linked-platforms'" />
      <AuditTab v-else />
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import TabNav, { type TabNavItem } from '@/components/TabNav.vue'
import UsersTab from '@/components/people/UsersTab.vue'
import GroupsTab from '@/components/people/GroupsTab.vue'
import PoliciesTab from '@/components/people/PoliciesTab.vue'
import AuditTab from '@/components/people/AuditTab.vue'
import PlatformInstancesTab from '@/components/people/PlatformInstancesTab.vue'
import { isIamPoliciesEnabled } from '@/composables/useIamFeature'
import { isPlatformLinksEnabled } from '@/composables/usePlatformLinksFeature'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const activeTab = ref<'users' | 'groups' | 'policies' | 'linked-platforms' | 'audit'>('users')

const tabNavItems = computed<TabNavItem[]>(() => {
  const tabs: TabNavItem[] = [
    {
      id: 'users',
      label: t('people.tabs.users'),
      icon: 'mdi:account-multiple',
      testid: 'tab-users',
    },
    {
      id: 'groups',
      label: t('people.tabs.groups'),
      icon: 'mdi:account-group',
      testid: 'tab-groups',
    },
  ]
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
    id: 'audit',
    label: t('people.tabs.audit'),
    icon: 'mdi:clipboard-text-clock',
    testid: 'tab-audit',
  })
  return tabs
})

function onTabNavChange(id: string) {
  if (
    id === 'users' ||
    id === 'groups' ||
    id === 'policies' ||
    id === 'linked-platforms' ||
    id === 'audit'
  ) {
    activeTab.value = id
  }
}
</script>
