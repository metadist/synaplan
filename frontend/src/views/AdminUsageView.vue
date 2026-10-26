<template>
  <MainLayout data-testid="view-admin-usage">
    <div class="min-h-screen overflow-x-hidden bg-chat px-3 py-4 sm:p-4 md:p-8">
      <div class="mx-auto w-full max-w-[100rem]">
        <button
          type="button"
          class="text-xs txt-secondary hover:txt-primary transition-colors mb-3 inline-flex items-center gap-1.5"
          data-testid="link-usage-back-operate"
          @click="router.push({ name: 'admin' })"
        >
          <Icon icon="heroicons:arrow-left" class="w-3.5 h-3.5" />
          {{ $t('people.backToOperate') }}
        </button>

        <PageHeader
          :title="$t('admin.usageBoard.title')"
          :subtitle="$t('admin.usageBoard.subtitle')"
          icon="mdi:chart-bar"
        />

        <p class="text-sm txt-secondary mb-4">{{ $t('admin.usageBoard.intro') }}</p>

        <div class="flex flex-wrap items-center gap-2 mb-4">
          <div
            class="inline-flex rounded-full surface-card border border-light-border/30 dark:border-dark-border/20 p-1"
            role="group"
            :aria-label="$t('admin.usageBoard.periodLabel')"
          >
            <button
              v-for="option in periodOptions"
              :key="option"
              type="button"
              class="px-3 py-1.5 text-sm rounded-full cursor-pointer"
              :class="period === option ? 'btn-primary' : 'txt-secondary'"
              :aria-pressed="period === option"
              :data-testid="`usage-period-${option}`"
              @click="setPeriod(option)"
            >
              {{ $t(`admin.usageBoard.period.${option}`) }}
            </button>
          </div>

          <select
            v-model="sort"
            class="px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            :aria-label="$t('admin.usageBoard.sortLabel')"
            data-testid="usage-sort"
            @change="reload"
          >
            <option value="cost">{{ $t('admin.usageBoard.sort.cost') }}</option>
            <option value="requests">{{ $t('admin.usageBoard.sort.requests') }}</option>
            <option value="tokens">{{ $t('admin.usageBoard.sort.tokens') }}</option>
          </select>
        </div>

        <p v-if="loading" class="text-sm txt-secondary">{{ $t('common.loading') }}</p>

        <div
          v-else-if="loadFailed"
          class="surface-card rounded-xl p-4 flex flex-col sm:flex-row sm:items-center gap-3"
        >
          <p role="alert" class="flex-1 text-sm text-red-600 dark:text-red-400">
            {{ $t('admin.usageBoard.loadFailed') }}
          </p>
          <button type="button" class="btn-secondary px-4 py-2.5 text-sm font-medium" @click="load">
            {{ $t('common.retry') }}
          </button>
        </div>

        <div
          v-else-if="!users.length"
          class="surface-card rounded-xl p-6 text-center space-y-3"
          data-testid="usage-empty"
        >
          <p class="text-sm txt-secondary">{{ $t('admin.usageBoard.empty') }}</p>
          <button
            v-if="period !== 'all'"
            type="button"
            class="btn-primary px-4 py-2.5 text-sm font-medium"
            data-testid="usage-empty-all-time"
            @click="setPeriod('all')"
          >
            {{ $t('admin.usageBoard.showAllTime') }}
          </button>
        </div>

        <template v-else>
          <ul class="space-y-3" data-testid="usage-user-list">
            <UsageUserRow
              v-for="(user, index) in users"
              :key="user.userId"
              :user="user"
              :rank="(page - 1) * perPage + index + 1"
              :period="period"
            />
          </ul>

          <div class="flex items-center justify-between gap-3 mt-4">
            <p class="text-xs txt-secondary">
              {{ $t('admin.usageBoard.usersCount', { total: totalUsers }) }}
            </p>
            <div class="flex items-center gap-2">
              <button
                type="button"
                class="btn-secondary px-4 py-2.5 text-sm font-medium"
                :disabled="page <= 1 || loading"
                data-testid="usage-prev"
                @click="goTo(page - 1)"
              >
                {{ $t('common.previous') }}
              </button>
              <span class="text-xs txt-secondary">{{ page }} / {{ totalPages }}</span>
              <button
                type="button"
                class="btn-secondary px-4 py-2.5 text-sm font-medium"
                :disabled="page >= totalPages || loading"
                data-testid="usage-next"
                @click="goTo(page + 1)"
              >
                {{ $t('common.next') }}
              </button>
            </div>
          </div>
        </template>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import UsageUserRow from '@/components/admin/usage/UsageUserRow.vue'
import {
  adminUsageApi,
  type UsagePeriod,
  type UsageSort,
  type UsageUser,
} from '@/services/api/adminUsageApi'

const router = useRouter()

const periodOptions: UsagePeriod[] = ['24h', '7d', '30d', 'all']
const period = ref<UsagePeriod>('30d')
const sort = ref<UsageSort>('cost')
const users = ref<UsageUser[]>([])
const totalUsers = ref(0)
const page = ref(1)
const perPage = 20
const totalPages = ref(1)
const loading = ref(true)
const loadFailed = ref(false)

// Period, sort and pager can fire loads that overlap; only the newest one
// may touch the board, or an older answer overwrites the selected filter.
let latestLoad = 0

async function load() {
  const generation = ++latestLoad
  loading.value = true
  loadFailed.value = false
  try {
    const result = await adminUsageApi.users({
      period: period.value,
      sort: sort.value,
      page: page.value,
      perPage,
    })
    if (generation !== latestLoad) return
    users.value = result.users
    totalUsers.value = result.totalUsers
    totalPages.value = result.totalPages
  } catch {
    if (generation === latestLoad) loadFailed.value = true
  } finally {
    if (generation === latestLoad) loading.value = false
  }
}

function reload() {
  page.value = 1
  void load()
}

function setPeriod(next: UsagePeriod) {
  period.value = next
  reload()
}

function goTo(next: number) {
  page.value = Math.min(Math.max(1, next), totalPages.value)
  void load()
}

onMounted(load)
</script>
