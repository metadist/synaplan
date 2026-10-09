<template>
  <section
    v-if="visible"
    class="surface-card rounded-2xl p-5 w-full max-w-md"
    :aria-label="$t('tours.checklist.title')"
    data-testid="section-getting-started"
  >
    <div class="flex items-start justify-between gap-3 mb-3">
      <div class="min-w-0">
        <h3 class="text-base font-semibold txt-primary">{{ $t('tours.checklist.title') }}</h3>
        <p class="text-xs txt-secondary" data-testid="text-getting-started-progress">
          {{ $t('tours.checklist.progress', { done: doneCount, total: items.length }) }}
        </p>
      </div>
      <button
        type="button"
        class="icon-ghost w-9 h-9 inline-flex items-center justify-center rounded-xl shrink-0"
        :aria-label="$t('tours.checklist.dismiss')"
        :title="$t('tours.checklist.dismiss')"
        data-testid="btn-getting-started-dismiss"
        @click="dismiss"
      >
        <XMarkIcon class="w-5 h-5" aria-hidden="true" />
      </button>
    </div>
    <ul class="space-y-1">
      <li v-for="item in items" :key="item.id">
        <RouterLink
          :to="item.to"
          class="flex items-start gap-3 rounded-xl px-2 py-2 hover-surface focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
          :data-testid="`item-getting-started-${item.id}`"
          :data-done="item.done"
        >
          <CheckCircleIcon
            v-if="item.done"
            class="w-5 h-5 shrink-0 text-green-600 dark:text-green-400"
            aria-hidden="true"
          />
          <span
            v-else
            class="w-5 h-5 shrink-0 rounded-full border-2 border-light-border dark:border-dark-border"
            aria-hidden="true"
          />
          <span class="min-w-0">
            <span
              class="block text-sm font-medium txt-primary"
              :class="item.done && 'line-through opacity-70'"
            >
              {{ $t(`tours.checklist.items.${item.id}.title`) }}
              <span v-if="item.done" class="sr-only">{{ $t('tours.checklist.doneLabel') }}</span>
            </span>
            <span class="block text-xs txt-secondary">
              {{ $t(`tours.checklist.items.${item.id}.where`) }}
            </span>
          </span>
        </RouterLink>
      </li>
    </ul>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { CheckCircleIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { useTour } from '@/composables/useTour'
import { useAuthStore } from '@/stores/auth'
import { useConfigStore } from '@/stores/config'
import { listFiles } from '@/services/filesService'
import { agentsApi } from '@/services/api/agentsApi'
import { availableApps } from '@/apps/catalog'
import { loadConnectedAppIds } from '@/apps/status'

const CHECKLIST_DISMISSED_ID = 'checklist.dismissed'

type ItemId = 'provider' | 'file' | 'assistant' | 'app'

interface Item {
  id: ItemId
  to: string
  done: boolean
}

const ITEM_TARGETS: Record<ItemId, string> = {
  provider: '/admin/setup',
  file: '/files',
  assistant: '/ai/assistants',
  app: '/apps',
}

const authStore = useAuthStore()
const configStore = useConfigStore()
const { seen, ensureLoaded, markSeen } = useTour()

const loaded = ref(false)
const status = ref<Partial<Record<ItemId, boolean>>>({})

/** A check that fails leaves its item out: an unknown state is never shown as done or open. */
async function check(id: ItemId, probe: () => Promise<boolean>): Promise<void> {
  try {
    const done = await probe()
    status.value = { ...status.value, [id]: done }
  } catch {
    // Feature off or unreachable: the item stays absent.
  }
}

const items = computed<Item[]>(() =>
  (Object.keys(ITEM_TARGETS) as ItemId[])
    .filter((id) => status.value[id] !== undefined)
    .map((id) => ({ id, to: ITEM_TARGETS[id], done: status.value[id] === true }))
)

const doneCount = computed(() => items.value.filter((item) => item.done).length)

const visible = computed(
  () =>
    loaded.value &&
    !seen.value.includes(CHECKLIST_DISMISSED_ID) &&
    items.value.length > 0 &&
    doneCount.value < items.value.length
)

async function dismiss(): Promise<void> {
  await markSeen(CHECKLIST_DISMISSED_ID)
}

onMounted(async () => {
  await ensureLoaded()
  if (seen.value.includes(CHECKLIST_DISMISSED_ID)) return
  if (authStore.isAdmin) {
    status.value = { provider: configStore.setup.chatReady !== false }
  }
  await Promise.all([
    check('file', async () => (await listFiles({ limit: 1 })).pagination.total > 0),
    check('assistant', async () => (await agentsApi.list()).length > 0),
    check('app', async () => (await loadConnectedAppIds(await availableApps())).size > 0),
  ])
  loaded.value = true
})
</script>
