<template>
  <section class="space-y-4" data-testid="section-project-chats">
    <div class="flex items-center justify-between gap-3">
      <p class="text-sm txt-secondary">{{ $t('projects.chats.intro') }}</p>
      <button
        type="button"
        class="btn-primary flex-shrink-0 px-4 py-2.5 text-sm font-medium"
        :disabled="creating"
        data-testid="btn-project-new-chat"
        @click="newChat"
      >
        {{ $t('projects.chats.new') }}
      </button>
    </div>

    <p
      v-if="chats.length === 0"
      class="rounded-xl border border-dashed border-light-border/40 dark:border-dark-border/30 px-4 py-8 text-center text-sm txt-secondary"
      data-testid="text-project-chats-empty"
    >
      {{ $t('projects.chats.empty') }}
    </p>
    <ul v-else class="space-y-2" data-testid="list-project-chats">
      <li
        v-for="chat in chats"
        :key="chat.id"
        class="flex items-center gap-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 px-3 py-2"
        :data-testid="`item-project-chat-${chat.id}`"
      >
        <button
          type="button"
          class="min-w-0 flex-1 text-left"
          data-testid="btn-project-chat-open"
          @click="open(chat.id)"
        >
          <span class="block truncate text-sm font-medium txt-primary">{{ chat.title }}</span>
          <span class="block text-xs txt-secondary">{{ formatDate(chat.updatedAt) }}</span>
        </button>
        <button
          type="button"
          class="icon-ghost inline-flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg"
          :aria-label="$t('projects.chats.leave')"
          :title="$t('projects.chats.leave')"
          data-testid="btn-project-chat-leave"
          @click="leave(chat.id)"
        >
          <ArrowUturnLeftIcon class="h-4 w-4" aria-hidden="true" />
        </button>
      </li>
    </ul>
  </section>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { ArrowUturnLeftIcon } from '@heroicons/vue/24/outline'
import { useProjectChatsStore } from '@/stores/projectChats'
import { useChatsStore } from '@/stores/chats'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'

const props = defineProps<{ projectId: string }>()

const { t, locale } = useI18n()
const router = useRouter()
const store = useProjectChatsStore()
const chatsStore = useChatsStore()
const { error: showError, success } = useNotification()
const { confirm } = useDialog()

const creating = ref(false)
const chats = computed(() => store.chatsOf(props.projectId))

function formatDate(iso: string): string {
  const time = Date.parse(iso)
  return Number.isNaN(time) ? '' : new Date(time).toLocaleString(locale.value)
}

async function open(chatId: number): Promise<void> {
  await chatsStore.loadChats()
  chatsStore.setActiveChat(chatId)
  await router.push('/')
}

async function newChat(): Promise<void> {
  creating.value = true
  try {
    const chat = await store.create(props.projectId)
    await open(chat.id)
  } catch {
    showError(t('projects.chats.createFailed'))
  } finally {
    creating.value = false
  }
}

async function leave(chatId: number): Promise<void> {
  const confirmed = await confirm({
    title: t('projects.chats.leaveTitle'),
    message: t('projects.chats.leaveMessage'),
    confirmText: t('projects.chats.leave'),
    cancelText: t('common.cancel'),
  })
  if (!confirmed) return
  try {
    await store.remove(props.projectId, chatId)
    success(t('projects.chats.left'))
  } catch {
    showError(t('projects.chats.leaveFailed'))
  }
}
</script>
