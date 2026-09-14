<template>
  <MainLayout data-testid="page-chats">
    <div class="container mx-auto px-6 py-8 max-w-7xl overflow-x-hidden">
      <PageHeader
        :title="$t('pageTitles.allChats')"
        :subtitle="$t('chat.browser.description')"
        icon="mdi:forum-outline"
      >
        <TabNav
          v-if="tabNavItems.length > 1"
          :model-value="activeTab"
          :tabs="tabNavItems"
          :aria-label="$t('pageTitles.allChats')"
          mobile-trigger-testid="tab-chats-mobile-trigger"
          mobile-menu-testid="tab-chats-mobile-menu"
          @update:model-value="onTabNavChange"
        />
      </PageHeader>

      <ChatBrowser v-if="activeTab === 'all'" />
      <IncomingChatsTab v-else-if="activeTab === 'incoming'" />
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import TabNav, { type TabNavItem } from '@/components/TabNav.vue'
import ChatBrowser from '@/components/ChatBrowser.vue'
import IncomingChatsTab from '@/components/chats/IncomingChatsTab.vue'
import { isIamSharingEnabled } from '@/composables/useIamFeature'
import { useI18n } from 'vue-i18n'

type ChatsTab = 'all' | 'incoming'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const sharingEnabled = computed(() => isIamSharingEnabled())

const tabFromRoute = (): ChatsTab =>
  route.name === 'chats-incoming' && sharingEnabled.value ? 'incoming' : 'all'

const activeTab = ref<ChatsTab>(tabFromRoute())

const tabNavItems = computed<TabNavItem[]>(() => {
  const tabs: TabNavItem[] = [
    {
      id: 'all',
      label: t('chats.tabs.all'),
      icon: 'mdi:forum-outline',
      testid: 'tab-chats-all',
    },
  ]
  if (sharingEnabled.value) {
    tabs.push({
      id: 'incoming',
      label: t('chats.tabs.incoming'),
      icon: 'mdi:inbox-arrow-down',
      testid: 'tab-chats-incoming',
    })
  }
  return tabs
})

function syncRoute(tab: ChatsTab) {
  const name = tab === 'incoming' ? 'chats-incoming' : 'chats'
  if (route.name !== name) {
    void router.replace({ name })
  }
}

function onTabNavChange(id: string) {
  if (id === 'incoming' && sharingEnabled.value) {
    activeTab.value = 'incoming'
    syncRoute('incoming')
    return
  }
  activeTab.value = 'all'
  syncRoute('all')
}

watch(
  () => [route.name, sharingEnabled.value] as const,
  () => {
    if (route.name === 'chats-incoming' && !sharingEnabled.value) {
      activeTab.value = 'all'
      void router.replace({ name: 'chats' })
      return
    }
    activeTab.value = tabFromRoute()
  },
  { immediate: true }
)
</script>
