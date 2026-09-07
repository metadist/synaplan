<template>
  <MainLayout data-testid="page-chats-incoming">
    <div class="container mx-auto px-6 py-8 max-w-3xl overflow-x-hidden">
      <PageHeader
        :title="$t('iam.incoming.title')"
        :subtitle="$t('iam.incoming.subtitle')"
        icon="mdi:inbox-arrow-down"
      >
        <router-link
          v-if="iamGroupsEnabled"
          to="/groups"
          class="btn-secondary px-4 py-2.5 rounded-lg inline-flex items-center gap-2"
          data-testid="link-incoming-my-groups"
        >
          <Icon icon="mdi:account-group-outline" class="w-4 h-4" />
          {{ $t('nav.myGroups') }}
        </router-link>
      </PageHeader>

      <!-- Filter row -->
      <div
        v-if="incomingStore.chats.length > 0"
        class="flex items-center justify-between gap-3 flex-wrap mb-4"
      >
        <div class="flex items-center gap-1.5" role="group" data-testid="filter-incoming">
          <button
            v-for="option in filterOptions"
            :key="option.value"
            type="button"
            class="pill !min-h-0 !py-1 !px-2.5 text-xs"
            :class="{ 'pill--active': filter === option.value }"
            :aria-pressed="filter === option.value"
            :data-testid="`btn-incoming-filter-${option.value}`"
            @click="filter = option.value"
          >
            <span>{{ option.label }}</span>
            <span class="text-[10px] opacity-70 tabular-nums">{{ option.count }}</span>
          </button>
        </div>
        <p class="text-xs txt-secondary" data-testid="text-incoming-hint">
          {{ $t('iam.incoming.hint') }}
        </p>
      </div>

      <div
        v-if="incomingStore.loading && !incomingStore.loaded"
        class="surface-card rounded-lg p-12 text-center"
      >
        <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
      </div>
      <div
        v-else-if="incomingStore.chats.length === 0"
        class="surface-card rounded-lg p-12 text-center"
        data-testid="incoming-empty"
      >
        <div
          class="w-14 h-14 rounded-2xl bg-[var(--brand-alpha-light)] flex items-center justify-center mx-auto mb-4"
        >
          <Icon icon="mdi:inbox-arrow-down-outline" class="w-7 h-7 text-[var(--brand)]" />
        </div>
        <p class="txt-primary font-medium">{{ $t('iam.incoming.emptyTitle') }}</p>
        <p class="txt-secondary text-sm mt-1">{{ $t('iam.incoming.emptyText') }}</p>
      </div>
      <div
        v-else-if="visibleChats.length === 0"
        class="surface-card rounded-lg p-12 text-center txt-secondary"
        data-testid="incoming-filter-empty"
      >
        {{ $t('common.noResults') }}
      </div>
      <ul v-else class="space-y-3" data-testid="list-incoming">
        <li
          v-for="chat in visibleChats"
          :key="chat.id"
          class="surface-card rounded-lg p-4 sm:p-5 flex items-center gap-4 cursor-pointer hover:bg-black/[0.02] dark:hover:bg-white/[0.03] transition-colors"
          :class="{ 'ring-1 ring-[var(--brand)]/30': chat.isNew }"
          :data-testid="`row-incoming-${chat.id}`"
          role="button"
          tabindex="0"
          @click="openChat(chat)"
          @keydown.enter.prevent="openChat(chat)"
          @keydown.space.prevent="openChat(chat)"
        >
          <div
            class="w-10 h-10 rounded-lg bg-[var(--brand-alpha-light)] flex items-center justify-center flex-shrink-0"
          >
            <Icon icon="mdi:chat-outline" class="w-5 h-5 text-[var(--brand)]" />
          </div>
          <div class="min-w-0 flex-1">
            <p class="txt-primary font-medium truncate">{{ chat.title }}</p>
            <div class="flex items-center gap-2 mt-1 flex-wrap">
              <ChatKindPill :kind="chat.kind" :label="chat.kindLabel" :is-new="chat.isNew" />
              <span v-if="chat.ownerName" class="text-xs txt-secondary truncate">
                {{ $t('iam.incoming.owner', { name: chat.ownerName }) }}
              </span>
              <span class="text-xs txt-secondary">·</span>
              <span class="text-xs txt-secondary">{{ $t(`iam.permission.${chat.access}`) }}</span>
              <span class="text-xs txt-secondary">·</span>
              <span class="text-xs txt-secondary" :title="formatDate(chat.sharedAt)">
                {{ formatRelativeTime(chat.sharedAt) }}
              </span>
            </div>
          </div>
          <Icon icon="mdi:chevron-right" class="w-5 h-5 txt-secondary flex-shrink-0" />
        </li>
      </ul>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import ChatKindPill from '@/components/iam/ChatKindPill.vue'
import { useIncomingStore } from '@/stores/incoming'
import { useChatsStore } from '@/stores/chats'
import { isIamGroupsEnabled } from '@/composables/useIamFeature'
import { useDateFormat } from '@/composables/useDateFormat'
import { kindOfSharedItem, type ChatKind } from '@/utils/chatKind'

type IncomingFilter = 'all' | 'new'

interface IncomingRow {
  id: number
  title: string
  kind: ChatKind
  kindLabel: string | null
  isNew: boolean
  ownerName: string | null
  access: string
  sharedAt: Date
}

const { t } = useI18n()
const router = useRouter()
const incomingStore = useIncomingStore()
const chatsStore = useChatsStore()
const { formatRelativeTime, formatDate } = useDateFormat()

const iamGroupsEnabled = computed(() => isIamGroupsEnabled())
const filter = ref<IncomingFilter>('all')

const rows = computed<IncomingRow[]>(() =>
  incomingStore.chats
    .map((item) => {
      const { kind, label } = kindOfSharedItem(item)
      return {
        id: Number(item.id),
        title: item.name,
        kind,
        kindLabel: label,
        isNew: item.isNew === true,
        ownerName: item.ownerName ?? null,
        access: item.permission,
        sharedAt: new Date((item.sharedAt ?? 0) * 1000),
      }
    })
    .sort(
      (a, b) => Number(b.isNew) - Number(a.isNew) || b.sharedAt.getTime() - a.sharedAt.getTime()
    )
)

const newCount = computed(() => rows.value.filter((r) => r.isNew).length)

const filterOptions = computed(() => [
  { value: 'all' as const, label: t('iam.incoming.filter.all'), count: rows.value.length },
  { value: 'new' as const, label: t('iam.incoming.new'), count: newCount.value },
])

const visibleChats = computed(() =>
  filter.value === 'new' ? rows.value.filter((r) => r.isNew) : rows.value
)

const openChat = (chat: IncomingRow) => {
  chatsStore.setActiveChat(chat.id)
  router.push('/')
}

onMounted(async () => {
  await incomingStore.load()
  // Opening this page is what "seeing" means: the red dot goes away, while the
  // rows keep their "new" marker until the next load so the user can spot them.
  if (incomingStore.hasNew) await incomingStore.markSeen()
})
</script>
