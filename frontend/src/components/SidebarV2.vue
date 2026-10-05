<template>
  <div class="v2-sidebar v2-desktop-chrome" data-testid="comp-sidebar-v2">
    <SidebarRail />
    <SidebarPanel />
  </div>

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
import SidebarRail from './sidebar/SidebarRail.vue'
import SidebarPanel from './sidebar/SidebarPanel.vue'
import ChatShareModal from './ChatShareModal.vue'
import ShareDialog from './iam/ShareDialog.vue'
import { useChatHistory, useChatShareDialog } from '@/composables/useChatHistory'

const { shareOwnerName, openPublicLinkFromIam, chatsStore } = useChatHistory()
const { shareModalOpen, shareModalChatId, shareModalChatTitle, iamShareOpen, iamShareResourceId } =
  useChatShareDialog()
</script>
