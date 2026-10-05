<template>
  <div
    class="v2-sidebar v2-desktop-chrome"
    :class="!panelDocked && 'v2-sidebar--collapsed'"
    :data-panel="panelDocked ? 'docked' : panelOverlay ? 'overlay' : 'folded'"
    data-testid="comp-sidebar-v2"
  >
    <SidebarRail />
    <Transition :name="panelDocked ? 'v2-panel-dock' : 'v2-panel-overlay'">
      <SidebarPanel v-if="panelVisible" :overlay="panelOverlay" @navigate="onPanelNavigate" />
    </Transition>
  </div>

  <Transition name="v2-panel-scrim">
    <button
      v-if="panelOverlay"
      type="button"
      tabindex="-1"
      class="v2-panel-scrim v2-desktop-chrome"
      :aria-label="$t('nav.collapseSidebar')"
      data-testid="btn-sidebar-v2-scrim"
      @click="closeOverlay"
    />
  </Transition>

  <ChatShareModal
    :is-open="shareModalOpen"
    :chat-id="shareModalChatId"
    :chat-title="shareModalChatTitle"
    @close="shareModalOpen = false"
    @shared="chatsStore.loadChats()"
    @unshared="chatsStore.loadChats()"
  />
  <ShareDialog
    :is-open="iamShareOpen"
    kind="conversation"
    :resource-id="iamShareResourceId"
    :resource-name="shareModalChatTitle"
    :owner-name="shareOwnerName"
    @close="iamShareOpen = false"
    @public-link="openPublicLinkFromIam"
  />
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue'
import SidebarRail from './sidebar/SidebarRail.vue'
import SidebarPanel from './sidebar/SidebarPanel.vue'
import ChatShareModal from './ChatShareModal.vue'
import ShareDialog from './iam/ShareDialog.vue'
import { useChatHistory, useChatShareDialog } from '@/composables/useChatHistory'
import { focusSidebarToggle, useSidebarLayout } from '@/composables/useSidebarLayout'
import { useSidebarStore } from '@/stores/sidebar'

const sidebarStore = useSidebarStore()
const { dockable, panelDocked, panelOverlay, panelVisible } = useSidebarLayout()
const { shareOwnerName, openPublicLinkFromIam, chatsStore } = useChatHistory()
const { shareModalOpen, shareModalChatId, shareModalChatTitle, iamShareOpen, iamShareResourceId } =
  useChatShareDialog()

// Crossing the dock width leaves no overlay behind.
watch(dockable, () => {
  sidebarStore.panelOverlayOpen = false
})

const closeOverlay = () => {
  sidebarStore.panelOverlayOpen = false
  focusSidebarToggle('expand')
}

/** Choosing a chat or a page in the overlay closes it; the docked panel stays. */
const onPanelNavigate = () => {
  if (panelOverlay.value) sidebarStore.panelOverlayOpen = false
}

const onKeydown = (event: KeyboardEvent) => {
  if (event.key !== 'Escape' || !panelOverlay.value || event.defaultPrevented) return
  closeOverlay()
}

onMounted(() => document.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>
